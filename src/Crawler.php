<?php

namespace Sbehnfeldt\CochraneCrawler;

use Exception;
use GuzzleHttp\Client;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Dom\HtmlNode;

class Crawler
{

    private ?Client $client;
    private array $headers;

    public function __construct()
    {
        $this->client = null;

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

    public function getClient()
    {
        if ( !$this->client ) {
            $this->client = new Client([
                'headers' => $this->headers,
                'cookies' => true
            ]);
        }
        return $this->client;

    }


    public function getTopics()
    {
        try {
            $response = $this->getClient()->get('http://www.cochranelibrary.com/home/topic-and-review-group-list.html?page=topic');
            $body     = $response->getBody();
            $contents = $body->getContents();
            $dom      = new Dom();
            $dom->loadStr($contents);
            $topics = $dom->find('.browse-by-list-item');

            return $topics;
        } catch (Exception $e) {
            exit($e->getMessage());
        }
    }


    public function getReviews(string $url, bool $follow = false): ?array
    {
        $summary = [];
        try {
            $response = $this->getClient()->get($url);
            $body     = $response->getBody();
            $contents = $body->getContents();

            $dom = new Dom();
            $dom->loadStr($contents);

            $n = 1;
            if ( $follow ) {
                $pages = $dom->find('ul.pagination-page-list li.pagination-page-list-item');
                if ( $pages ) {
                    echo( count($pages)  . " page(s): ");
                } else {
                    echo( "1 page: ");
                }
                echo( "$n... ");
                $n++;
            }

            $reviews = $dom->find('.search-results-item');
            foreach ($reviews as $review) {
                $summary[] = $this->getReview($review);
            }

            if ($follow) {
                /** @var HtmlNode $page */
                foreach ($pages as $page) {
                    $classes = explode(' ', $page->getAttribute('class'));
                    if (in_array('active', $classes)) {
                        continue;
                    }
                    $links = $page->find( 'a' );
                    if ( $links ) {
                        /** @var HtmlNode $link */
                        $link = $links[0];
                        $href = $link->getAttribute('href');
                        echo( "$n... ");
                        $n++;
                        $summary = array_merge($summary, $this->getReviews($href, false ));
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

            $summary[ 'date' ] = 'Not found';
            $block           = $review->find('.search-result-metadata-block');
            if (count($block)) {
                $date            = $block->find('.search-result-date div');
                if ( count($date)) {
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
