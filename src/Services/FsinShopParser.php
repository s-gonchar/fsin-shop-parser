<?php


namespace Services;


use dto\ProductDto;
use Entities\Agency;
use Entities\Product;
use Entities\Region;
use Entities\Shop;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SessionCookieJar;
use PHPHtmlParser\Dom;
use Repositories\AgencyRepository;
use Repositories\LogRepository;
use Repositories\ProductRepository;
use Repositories\RegionRepository;
use Repositories\ShopRepository;

class FsinShopParser
{
    private const BASE_URI = 'https://fsin-shop.ru/';
    private const REGIONS_URI = 'https://fsin-shop.ru/ajax/form.php?form_id=city_chooser';
    private const PART_URI_CATALOG = '/catalog/neprodovolstvennye_tovary/taksofonnye_karty_svyazi/';
    private const DOMAIN = 'fsin-shop.ru';

    private Client $client;
    private LogRepository $logRepository;
    private RegionRepository $regionRepository;
    private AgencyRepository $agencyRepository;
    private ProductRepository $productRepository;
    private LogService $logService;
    private ShopRepository $shopRepository;

    public function __construct(
        LogService $logService,
        LogRepository $logRepository,
        RegionRepository $regionRepository,
        AgencyRepository $agencyRepository,
        ShopRepository $shopRepository,
        ProductRepository $productRepository
    ) {
        $jar = new SessionCookieJar('PHPSESSID', true);
        $this->client = new Client([
            'base_uri' => self::BASE_URI,
            'cookies' => $jar,
        ]);
        $this->logRepository = $logRepository;
        $this->regionRepository = $regionRepository;
        $this->agencyRepository = $agencyRepository;
        $this->productRepository = $productRepository;
        $this->logService = $logService;
        $this->shopRepository = $shopRepository;
    }

    /**
     * @throws \Exception
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function parseRegions()
    {
        $dom = new Dom();
        $dom->loadFromUrl(self::REGIONS_URI);
        $regionsBlocks = $dom->find('.regions');
        /** @var Dom\Node\HtmlNode $regionsBlock */
        $regionsBlock = $regionsBlocks[0] ?? null;
        if (!$regionsBlock) {
            throw new \Exception('Regions parse failed');
        }
        $items = $regionsBlock->find('.item');
        foreach ($items as $element) {
            $externalId = (int)$element->getAttribute('data-id');
            $name = $element->text();
            $region = $this->regionRepository->findOneByExternalId($externalId)
                ?: Region::create($name, $externalId);
            $this->regionRepository->persist($region);
        }

        $this->regionRepository->flush();
    }

    public function parseAgencies()
    {
        $dom = new Dom();
        $dom->loadFromUrl(self::REGIONS_URI);
        $regionsBlocks = $dom->find('.regions');
        /** @var Dom\Node\HtmlNode $agenciesBlock */
        $agenciesBlock = $regionsBlocks[1] ?? null;
        if(!$agenciesBlock) {
            throw new \Exception('Agencies parse failed');
        }
        $parentBlocks = $agenciesBlock->find('.parent_block');
        foreach ($parentBlocks as $parentBlock) {
            $regionExternalId = (int) $parentBlock->getAttribute('data-id');
            $region = $this->regionRepository->findOneByExternalId($regionExternalId);
            $items = $parentBlock->find('.item');
            foreach ($items as $element) {
                $agencyExternalId = (int) $element->getAttribute('data-id');
                $name = $element->text();
                $agency = $this->agencyRepository->findOneByExternalId($agencyExternalId)
                    ?: Agency::create($region, $name, $agencyExternalId);
                $this->agencyRepository->persist($agency);
            }
        }

        $this->agencyRepository->flush();
    }

    private function parseShops()
    {
        $dom = new Dom();
        $dom->loadFromUrl(self::REGIONS_URI);
        /** @var Dom\Node\HtmlNode $shopsBlock */
        $shopsBlock = $dom->find('.cities')[0] ?? null;
        if(!$shopsBlock) {
            throw new \Exception('Shops parse failed');
        }
        $items = $shopsBlock->find('.item');
        foreach ($items as $element) {
            $agencyExternalId = (int) $element->getAttribute('data-id');
            $agency = $this->agencyRepository->getByExternalId($agencyExternalId);
            $a = $element->find('a');
            $name = $a->text();
            $externalId = (int) $a->getAttribute('data-id');
            $shop = $this->shopRepository->findOneByExternalId($externalId)
                ?: Shop::create($agency, $name, $externalId);
            $this->shopRepository->persist($shop);
        }

        $this->shopRepository->flush();
    }

    public function parse()
    {
        try {
            $log = $this->logRepository->findLastOneSinceDt(new \DateTime('yesterday'));
            if ($log && $log->isSuccess()) {
                return;
            }

            $this->parseRegions();
            $this->parseAgencies();
            $this->parseShops();
            foreach ($this->shopRepository->getAll() as $shop) {
                $this->parseProductsByShop($shop);
            }
            $this->logService->log();
        } catch (\Throwable $e) {
            $this->logService->log($e);
        }
    }


    /**
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\CircularException
     * @throws \PHPHtmlParser\Exceptions\StrictException
     * @throws \PHPHtmlParser\Exceptions\NotLoadedException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \PHPHtmlParser\Exceptions\UnknownChildTypeException
     * @throws \PHPHtmlParser\Exceptions\ContentLengthException
     * @throws \PHPHtmlParser\Exceptions\LogicalException
     * @throws \Exception
     */
    private function parseProductsByShop(Shop $shop)
    {
        $cookieJar = CookieJar::fromArray([
            'current_region' => $shop->getExternalId(),
        ], self::DOMAIN);
        $options = [
            'cookies' => $cookieJar,
        ];
        $response = $this->client->request(
            'GET',
            self::PART_URI_CATALOG,
            $options
        );

        $html = $response->getBody();
        if ($html) {
            $dom = new Dom();
            $dom->loadStr($html);
            /** @var Dom\Node\HtmlNode[] $itemNodes */
            $itemNodes = $dom->find('.catalog_item');
            foreach ($itemNodes as $itemNode) {
                /** @var Dom\Node\HtmlNode $titleNode */
                $titleNode = $itemNode->find('.item-title')[0];
                /** @var Dom\Node\HtmlNode $a */
                $a = $titleNode->find('a')[0];
                /** @var Dom\Node\HtmlNode $itemStock */
                $itemStock = $itemNode->find('.item-stock')[0] ?? null;
                /** @var Dom\Node\HtmlNode $value */
                $value = $itemStock ? $itemStock->find('.value')[0] : null;
                $productDto = new ProductDto();
                $productDto->externalId = explode('_', $itemNode->getAttribute('id'))[2];
                $productDto->name = $a->innerText();
                $productDto->href = $a->getAttribute('href');
                $productDto->inStock = $value && $value->innerText() === "Есть в наличии";
                $product = $this->productRepository->findOneByExternalIdAndShop($productDto->externalId, $shop);
                if (!$product) {
                    $product = Product::create(
                        $shop,
                        $productDto->name,
                        $productDto->externalId,
                        $productDto->inStock,
                        $productDto->href
                    );
                } else {
                    $product->setName($productDto->name)
                        ->setInStock($productDto->inStock)
                        ->setLink($productDto->href)
                    ;
                }
                $this->productRepository->persist($product);
                $this->logService->printProductMessage($product);
            }
            $this->productRepository->flush();
        }
    }
}