<?php

namespace Sbehnfeldt\CochraneCrawler;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Dom\HtmlNode;
use PHPHtmlParser\Exceptions\ChildNotFoundException;
use PHPHtmlParser\Exceptions\CircularException;
use PHPHtmlParser\Exceptions\NotLoadedException;
use PHPHtmlParser\Exceptions\StrictException;


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
     *
     * This pattern provides a default client, if none is provided ahead of time.
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

    /**
     * Crawl the Cochrane review website.  Main entry point for the Crawler.
     *
     * @param  string  $topicsUrl  URL of the Cochrane Library "Browse by Topic" page
     *
     * @return void
     */
    public function crawl(string $topicsUrl): void
    {
        try {
            $topicsPageContents = $this->fetchTopicsPage($topicsUrl);
            $this->parseTopicsBrowsePage($topicsPageContents);
            $this->crawlAllTopics();
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
     * Iterate through the review summaries.
     *
     * This function is intended to be called after the crawling has been done.
     *
     * @return \Generator
     */
    public function getNextSummary()
    {
        for ($i = 0; $i < count($this->summary); $i++) {
            yield $this->summary[$i];
        }
    }


    /**
     * Download the raw HTML of the Cochrane "Topics" page.
     *
     * @param  string  $url  URL of the Cochrane "Topics" page.
     *
     * @return string Raw contents of the Cochrane "Topics" page.
     * @throws GuzzleException
     */
    public function fetchTopicsPage(string $url): string
    {
        // During dev, it is often convenient to break apart chainable calls
        // to inspect the values step-by-step.  It also makes the code clearer,
        // subsequently benefiting the maintenance staff.
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
    public function parseTopicsBrowsePage(string $contents): void
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
                'contents' => [],
                'reviews'  => []
            ];

            $it->next();
        }

        return;
    }

    /**
     * Having scanned the "Browse by Topics" page, follow the links found therein,
     * looking for reviews.
     *
     * @return void
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws GuzzleException
     * @throws NotLoadedException
     * @throws StrictException
     */
    public function crawlAllTopics()
    {
        foreach ($this->topics as $i => &$data) {
            // Crawl a single topic
            echo $data['topic'] . PHP_EOL;
            echo '   Retrieving page 1 ...' . PHP_EOL;
            $response = $this->getGuzzleClient()->get($data['urls'][0]);
            $body     = $response->getBody();
            $contents = [$body->getContents()];

            $this->getDom()->loadStr($contents[0]);

            // Identify the links from the "Paging" control
            $paginationItems = $this->getDom()->find('ul.pagination-page-list li.pagination-page-list-item');
            $it              = $paginationItems->getIterator();
            $urls            = [];
            while ($it->valid()) {
                $current = $it->current();
                $classes = explode(' ', $current->getAttribute('class'));
                if ( ! in_array('active', $classes)) {
                    $links = $current->find('a');
                    if ($links) {
                        $link   = $links[0];
                        $urls[] = $link->getAttribute('href');
                    }
                }
                $it->next();
            }


            // Retrieve and scan the remaining review pages for this topic.
            // It is not uncommon for a "get" call to Cochrane to fail,
            // so we continue to try to fetch pages until we have them all.
            // Every time a fetch is successful, we null out that URL;
            // we know we are done when the $urls[] array is empty.

            // It is, in theory, possible that a URL fails repeatedly,
            // so we include a retry limit, just to be safe.
            $retries = 3;   // Failsafe
            while ((count($urls) > 0) and ($retries > 0)) {
                echo(sprintf('   Retrieving %d additional page(s): ', count($urls)));
                $promises = [];
                for ($j = 0; $j < count($urls); $j++) {
                    $promises[] = $this->getGuzzleClient()->getAsync($urls[$j]);
                }
                $responses = Promise\Utils::settle($promises)->wait();
                foreach ($responses as $k => $response) {
                    echo(sprintf("%d... ", $k + 1));
                    if ('fulfilled' === $response['state']) {
                        $body       = $response['value']->getBody();
                        $contents[] = $body->getContents();   // Append this page (ordering is not important)
                        $urls[$k]   = null;                   // Erase a URL which has been successfully fetched
                    } else {
                        $reason = $response['reason'];
                        echo(sprintf("FAILED retrieving page %d: ({$reason->getCode()})... ", $j + 1));
                    }
                }

                // Remove all the empty values from the URL arrays; retry any remaining URLs next time through the loop
                $urls = array_values(array_filter($urls));
                $retries--;
                echo(PHP_EOL);
            }

            echo(sprintf("   Scanning %d page(s) in total: ", count($contents)));
            $page = 1;
            foreach ($contents as $content) {
                echo("Page $page... ");
                $this->parseTopicSubpage($data['topic'], $content);
                $page++;
            }
            echo(PHP_EOL);
        }
    }


    /**
     * Scan a "Topics" page for all review summaries and extract the meta-data.
     *
     * @param  string  $topic
     * @param  string  $contents
     *
     * @return void
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     */
    public function parseTopicSubPage(string $topic, string $contents)
    {
        $this->getDom()->loadStr($contents);
        $reviewItems = $this->getDom()->find('.search-results-item');
        foreach ($reviewItems as $item) {
            $this->summary[] = $this->getReview($topic, $item);
        }
    }

    /**
     * Parse a ".search-results-item" node for review meta-data to be included in the summary
     *
     * @param $topic
     * @param  HtmlNode  $review
     *
     * @return array
     */
    public function getReview($topic, HtmlNode $review): array
    {
        $summary = [
            'topic' => $topic,
        ];
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

