<?php

namespace Sbehnfeldt\CochraneCrawler;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Each;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Dom\Collection;
use PHPHtmlParser\Dom\HtmlNode;
use PHPHtmlParser\Exceptions\ChildNotFoundException;
use PHPHtmlParser\Exceptions\CircularException;
use PHPHtmlParser\Exceptions\NotLoadedException;
use PHPHtmlParser\Exceptions\StrictException;
use Psr\Http\Message\ResponseInterface;

/**
 * Object for crawling the Cochrane website.
 *
 * All necessary functionality is provided in a self-contained class,
 * making it easier to re-use from either the command line, a web-app, or otherwise.
 */
class Crawler
{
    // Array of review meta-data
    private array $summary = [];

    // Array of topic data
    private array $topics = [];

    // Guzzle HTTP client
    private ?Client $client;

    // HTML parser
    private ?Dom $dom;

    // HTTP headers
    private array $headers;

    public function __construct()
    {
        $this->client = null;
        $this->dom    = null;

        // For convenience's sake, the HTTP headers are hard-coded here,
        // rather than being provided by DI or config file or similar.
        // I copied them directly from what I found in my browser's dev tools.
        // Not sure which ones are strictly necessary, but the Cochrane website
        // seems very reluctant to place nicely with you if it doesn't find the headers it demands.
        $this->headers = [
            'Host'                      => 'www.cochranelibrary.com',
            'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv =>126.0) Gecko/20100101 Firefox/126.0',
            'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language'           => 'en-US,en;q=0.5',
            'Accept-Encoding'           => 'gzip, deflate',
            'Connection'                => 'keep-alive',
            'Upgrade-Insecure-Requests' => 1,
            'Sec-Fetch-Dest'            => 'document',
            'Sec-Fetch-Mode'            => 'navigate',
            'Sec-Fetch-Site'            => 'none',
            'Sec-Fetch-User'            => '?1',
            'Priority'                  => 'u=1',
            'TE'                        => 'trailers'
        ];
    }

    /**
     * Get the Guzzle library client object for performing HTTP communication.
     *
     * @return Client
     */
    public function getGuzzleClient(): Client
    {
        if ( ! $this->client) {
            $this->client = new Client([
                'headers' => $this->headers,
                'cookies' => true
            ]);
        }

        return $this->client;
    }

    public function setGuzzleClient(Client $client): void
    {
        $this->client = $client;
    }

    /**
     * Get the HTML-parsing object.
     *
     * @return Dom
     */
    public function getDom(): Dom
    {
        if ( ! $this->dom) {
            $this->dom = new Dom();
        }

        return $this->dom;
    }

    public function setDom(Dom $dom): void
    {
        $this->dom = $dom;
    }

    public function crawl(string $topicsUrl)
    {
        try {
            $topicsPageContents = $this->fetchTopicsPage($topicsUrl);
            $this->parseTopicsPage($topicsPageContents);
            $this->fetchEachTopicFrontPage();
        } catch (GuzzleException $e) {
            echo(sprintf('Fatal error loading Cochrane library topics page "%s"', $topicsUrl));
            exit($e->getMessage());
        } catch (ChildNotFoundException|NotLoadedException|CircularException|StrictException $e) {
            echo(sprintf('Fatal error parsing Cochrane library topics page "%s"', $topicsUrl));
            exit($e->getMessage());
        }

        return;
    }


    /**
     * Download the raw HTML of the Cochrane "Topics" page.
     *
     * @param  string  $url  URL of the Cochrane "Topics" page.
     *
     * @return string Raw contents of the Cochrane "Topics" page.
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function fetchTopicsPage(string $url): string
    {
        // During dev, it is often convenient to break apart chainable calls
        // to inspect the values step-by-step.  It is makes the code clearer,
        // benefiting the maintenance staff.
        $response = $this->getGuzzleClient()->get($url);
        $body     = $response->getBody();
        $contents = $body->getContents();

        return $contents;
    }


    /**
     * From the raw HTML for the Cochrane "Topics" page,
     * map each topic name to the URL of the main page for that topic.
     *
     * @param  string  $contents  The HTML of the "Topics" page
     *
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     */
    public function parseTopicsPage(string $contents): void
    {
        $this->getDom()->loadStr($contents);
        $items = $this->getDom()->find('.browse-by-list-item');
        $it    = $items->getIterator();
        while ($it->valid()) {
            $this->getDom()->loadStr($it->current());
            $link           = $this->getDom()->find('a');
            $href           = $link->getAttribute('href');
            $button         = $this->getDom()->find('button');
            $this->topics[] = [
                'topic'    => $button->innerHtml,
                'urls'     => [$href],
                'promises' => [],
                'contents' => [],
                'doms'     => []
            ];

            $it->next();
        }

        return;
    }

    public function fetchEachTopicFrontPage()
    {
        $promises = [];
        foreach ($this->topics as $i => &$data) {
            $promises[] = $this->getGuzzleClient()->getAsync($data['urls'][0]);
        }

        // We now have a promise for page 1 for each topic (in the same order as $this->topics);
        // resolve by scanning the page for pagination links.
        $crawler = $this;
        Each::of(
            $promises,
            function ($response, $i) use ($crawler) {
                $crawler->parseFrontTopicPage($response, $i, false);
            },
            function ($reason, $index) {
                echo("Failed index $index: \n");
                echo($reason->getMessage());
            }
        )->wait();

    }

    public function parseFrontTopicPage($response, $i)
    {
        $topic     = $this->topics[$i];
        $topicName = $topic['topic'];

        echo(sprintf('Scanning page "%s" for pagination URLs... ', $topicName));

        $body     = $response->getBody();
        $contents = $body->getContents();
        $this->getDom()->loadStr($contents);
        $paginationItems = $this->getDom()->find('ul.pagination-page-list li.pagination-page-list-item');
        $it              = $paginationItems->getIterator();
        while ($it->valid()) {
            $current = $it->current();
            $classes = explode(' ', $current->getAttribute('class'));
            if ( ! in_array('active', $classes)) {
                $links = $current->find('a');
                if ($links) {
                    /** @var HtmlNode $link */
                    $link                       = $links[0];
                    $href                       = $link->getAttribute('href');
                    $this->topics[$i]['urls'][] = $href;
//                $summary = array_merge($summary, $this->getReviews($href, false));
                }
            }

            $it->next();
        }

        echo(sprintf("%d page(s)\n", count($this->topics[$i]['urls'])));

        return;
    }


    public function fetchTopicPage($url)
    {
        return $this->getGuzzleClient()->getAsync($url);
    }


    /**
     * Get the contents
     *
     * @param  string  $topic
     * @param  ResponseInterface  $response
     * @param  bool  $follow
     *
     * @return int
     * @throws ChildNotFoundException
     * @throws NotLoadedException
     */
    public function parseTopicPage(string $topic, ResponseInterface $response, bool $follow = false)
    {
        echo "Parsing Topic page: $topic\n";
        $body     = $response->getBody();
        $contents = $body->getContents();
        $this->getDom()->loadStr($contents);


        $reviewItems = $this->getDom()->find('.search-results-item');
        foreach ($reviewItems as $item) {
            $this->summary[] = $this->getReview($item);
        }
    }

    public function fetchReviews(string $url)
    {
        return $this->getGuzzleClient()->getAsync($url);
    }


    public function getReviews(string $url, bool $follow = false): ?array
    {
        $summary = [];
        try {
            $response = $this->getGuzzleClient()->getAsync($url);
            $body     = $response->getBody();
            $contents = $body->getContents();

            $dom = new Dom();
            $dom->loadStr($contents);

            $n = 1;

            if ($follow) {
                $pages = $dom->find('ul.pagination-page-list li.pagination-page-list-item');
                if ($pages) {
                    echo(count($pages) . " page(s): ");
                } else {
                    echo("1 page: ");
                }
                echo("$n... ");
                $n++;
            }

            $reviews = $dom->find('.search-results-item');
            foreach ($reviews as $review) {
                $this->summary[] = $this->getReview($review);
            }

            if ($follow) {
                /** @var HtmlNode $page */
                foreach ($pages as $page) {
                    $classes = explode(' ', $page->getAttribute('class'));
                    if (in_array('active', $classes)) {
                        continue;
                    }
                    $links = $page->find('a');
                    if ($links) {
                        /** @var HtmlNode $link */
                        $link = $links[0];
                        $href = $link->getAttribute('href');
                        echo("$n... ");
                        $n++;
                        $summary = array_merge($summary, $this->getReviews($href, false));
                    }
                }
            }
        } catch (Exception $e) {
            echo $e->getMessage() . "\n";
        }

        return $summary;
    }


    public function getReview(HtmlNode $review): array
    {
        $summary = [];
        try {
            $link = $review->find('.search-results-item-body h3.result-title a');

            if ($link) {
                /** @var HtmlNode $node */
                $node             = $link[0];
                $summary['title'] = $node->innerHtml();
                $summary['url']   = "https://www.cochranelibrary.com/" . $node->getAttribute('href');
            }

            $authors = $review->find('.search-result-authors div');
            if (count($authors)) {
                $summary['authors'] = $authors->innerHtml;
            } else {
                $summary['authors'] = 'Not found';
            }

            $summary['date'] = 'Not found';
            $block           = $review->find('.search-result-metadata-block');
            if (count($block)) {
                $date = $block->find('.search-result-date div');
                if (count($date)) {
                    $summary['date'] = $date ? $date->innerHtml : '';
                }
            }
        } catch (Exception $e) {
            echo($e->getMessage());

            return [];
        }

        return $summary;
    }
}
