<?php


namespace Services\FsinShopParser;


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
use Services\LogService;

class FsinShopParser
{
    private const BASE_URI = 'https://fsin-shop.ru';
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

    public function parse(Filter $filter = null)
    {
        try {
            $log = $this->logRepository->findLastOneSinceDt(new \DateTime('yesterday'));
            if ($log && $log->isSuccess()) {
                return;
            }

            $this->parseRegions();
            $this->parseAgencies();
            $this->parseShops();
            $this->parseProducts($filter->shopExternalId);
            $this->logService->log();
        } catch (\Throwable $e) {
            $this->logService->log($e);
        }
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
        /** @var Dom\Node\HtmlNode[] $items */
        $items = $regionsBlock->find('.item');
        foreach ($items as $element) {
            $externalId = (int)$element->getAttribute('data-id');
            $name = $element->innerText();
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
        if (!$agenciesBlock) {
            throw new \Exception('Agencies parse failed');
        }
        $parentBlocks = $agenciesBlock->find('.parent_block');
        foreach ($parentBlocks as $parentBlock) {
            $regionExternalId = (int)$parentBlock->getAttribute('data-id');
            $region = $this->regionRepository->findOneByExternalId($regionExternalId);
            $items = $parentBlock->find('.item');
            foreach ($items as $element) {
                $agencyExternalId = (int)$element->getAttribute('data-id');
                $name = $element->innerText();
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
        if (!$shopsBlock) {
            throw new \Exception('Shops parse failed');
        }
        $items = $shopsBlock->find('.item');
        foreach ($items as $element) {
            $agencyExternalId = (int)$element->getAttribute('data-id');
            $agency = $this->agencyRepository->findOneByExternalId($agencyExternalId);
            if (!$agency) {
                continue;
            }
            $a = $element->find('a');
            $name = $a->text();
            $externalId = (int)$a->getAttribute('data-id');
            $shop = $this->shopRepository->findOneByExternalId($externalId)
                ?: Shop::create($agency, $name, $externalId);
            $this->shopRepository->persist($shop);
        }

        $this->shopRepository->flush();
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

        $json = $this->getHtmlContentByCookieAndUrl($cookieJar);
        if ($json) {
            $json = stripcslashes($json);
            $dom = new Dom();
            $dom->loadStr($json);
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
                        ->setLink($productDto->href);
                }
                $this->productRepository->persist($product);
                $this->logService->printProductMessage($product);
            }
            $this->productRepository->flush();
        }
    }

    private function parseProducts($shopExternalId = null)
    {
        $shops = $shopExternalId
            ? [$this->shopRepository->findOneByExternalId($shopExternalId)]
            : $this->shopRepository->getAll();
        foreach ($shops as $shop) {
            $this->parseProductsByShop($shop);
        }
    }

    private function getHtmlContentByCookieAndUrl($cookieJar)
    {
        $ch = curl_init();
        $query = '?bxrand=' . rand();
        $url = self::BASE_URI . self::PART_URI_CATALOG;
        $urlWithQuery = self::BASE_URI . self::PART_URI_CATALOG . $query;
        curl_setopt($ch, CURLOPT_URL, $urlWithQuery);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');

        $headers = array();
        $headers[] = 'Authority: fsin-shop.ru';
        $headers[] = 'Sec-Ch-Ua: \" Not;A Brand\";v=\"99\", \"Google Chrome\";v=\"91\", \"Chromium\";v=\"91\"';
        $headers[] = 'Bx-Ref: ';
        $headers[] = 'Bx-Cache-Mode: HTMLCACHE';
        $headers[] = 'Sec-Ch-Ua-Mobile: ?0';
        $headers[] = 'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.77 Safari/537.36';
        $headers[] = 'Bx-Action-Type: get_dynamic';
        $headers[] = 'Accept: */*';
        $headers[] = 'Sec-Fetch-Site: same-origin';
        $headers[] = 'Sec-Fetch-Mode: cors';
        $headers[] = 'Sec-Fetch-Dest: empty';
        $headers[] = 'Referer: ' . $url;
        $headers[] = 'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
        $cookie = [];
        foreach ($cookieJar->toArray() as $item) {
            $key = $item['Name'];
            $value = $item['Value'];
            $cookie[] = "$key=$value";
        }
        $headers[] = "Cookie: " . implode('; ', $cookie);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        return $result;
    }

    private function fixJSON($json) {
        $newJSON = '';

        $jsonLength = strlen($json);
        for ($i = 0; $i < $jsonLength; $i++) {
            if ($json[$i] == '"' || $json[$i] == "'") {
                $nextQuote = strpos($json, $json[$i], $i + 1);
                $quoteContent = substr($json, $i + 1, $nextQuote - $i - 1);
                $newJSON .= '"' . str_replace('"', "'", $quoteContent) . '"';
                $i = $nextQuote;
            } else {
                $newJSON .= $json[$i];
            }
        }

        return $newJSON;
    }

    private function jsonFixer($json){
        $patterns     = [];
        /** garbage removal */
        $patterns[0]  = "/([\s:,\{}\[\]])\s*'([^:,\{}\[\]]*)'\s*([\s:,\{}\[\]])/"; //Find any character except colons, commas, curly and square brackets surrounded or not by spaces preceded and followed by spaces, colons, commas, curly or square brackets...
        $patterns[1]  = '/([^\s:,\{}\[\]]*)\{([^\s:,\{}\[\]]*)/'; //Find any left curly brackets surrounded or not by one or more of any character except spaces, colons, commas, curly and square brackets...
        $patterns[2]  =  "/([^\s:,\{}\[\]]+)}/"; //Find any right curly brackets preceded by one or more of any character except spaces, colons, commas, curly and square brackets...
        $patterns[3]  = "/(}),\s*/"; //JSON.parse() doesn't allow trailing commas
        /** reformatting */
        $patterns[4]  = '/([^\s:,\{}\[\]]+\s*)*[^\s:,\{}\[\]]+/'; //Find or not one or more of any character except spaces, colons, commas, curly and square brackets followed by one or more of any character except spaces, colons, commas, curly and square brackets...
        $patterns[5]  = '/["\']+([^"\':,\{}\[\]]*)["\']+/'; //Find one or more of quotation marks or/and apostrophes surrounding any character except colons, commas, curly and square brackets...
        $patterns[6]  = '/(")([^\s:,\{}\[\]]+)(")(\s+([^\s:,\{}\[\]]+))/'; //Find or not one or more of any character except spaces, colons, commas, curly and square brackets surrounded by quotation marks followed by one or more spaces and  one or more of any character except spaces, colons, commas, curly and square brackets...
        $patterns[7]  = "/(')([^\s:,\{}\[\]]+)(')(\s+([^\s:,\{}\[\]]+))/"; //Find or not one or more of any character except spaces, colons, commas, curly and square brackets surrounded by apostrophes followed by one or more spaces and  one or more of any character except spaces, colons, commas, curly and square brackets...
        $patterns[8]  = '/(})(")/'; //Find any right curly brackets followed by quotation marks...
        $patterns[9]  = '/,\s+(})/'; //Find any comma followed by one or more spaces and a right curly bracket...
        $patterns[10] = '/\s+/'; //Find one or more spaces...
        $patterns[11] = '/^\s+/'; //Find one or more spaces at start of string...

        $replacements     = [];
        /** garbage removal */
        $replacements[0]  = '$1 "$2" $3'; //...and put quotation marks surrounded by spaces between them;
        $replacements[1]  = '$1 { $2'; //...and put spaces between them;
        $replacements[2]  = '$1 }'; //...and put a space between them;
        $replacements[3]  = '$1'; //...so, remove trailing commas of any right curly brackets;
        /** reformatting */
        $replacements[4]  = '"$0"'; //...and put quotation marks surrounding them;
        $replacements[5]  = '"$1"'; //...and replace by single quotation marks;
        $replacements[6]  = '\\$1$2\\$3$4'; //...and add back slashes to its quotation marks;
        $replacements[7]  = '\\$1$2\\$3$4'; //...and add back slashes to its apostrophes;
        $replacements[8]  = '$1, $2'; //...and put a comma followed by a space character between them;
        $replacements[9]  = ' $1'; //...and replace by a space followed by a right curly bracket;
        $replacements[10] = ' '; //...and replace by one space;
        $replacements[11] = ''; //...and remove it.

        $result = preg_replace($patterns, $replacements, $json);

        return $result;
    }
}