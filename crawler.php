#!/usr/bin/env php
<?php
namespace Sbehnfeldt\CochraneCrawler;

use Exception;
use GuzzleHttp\Promise;
use GuzzleHttp\Promise\Each;
use PHPHtmlParser\Dom;


require_once 'vendor/autoload.php';


try {
    $crawler = new Crawler();
    $topics  = $crawler->getTopics();

    $summary = [];
    $dom     = new Dom();
    $it      = $topics->getIterator();

    $topics = [];
    $promises = [];
    while ($it->valid()) {
        $dom->loadStr($it->current());
        $link = $dom->find('a');
        $href = $link->getAttribute('href');
        $button = $dom->find('button');
        $topics[] = $button->innerHtml;

        $promises[] = $crawler->fetchReviews($href);
        $it->next();
    }

    Each::of(
        $promises,
        function ($response, $index) use ($crawler) {
            $crawler->processReviews($response);
        },
        function ($reason, $index) use ($topics) {
            echo(" FAILED: {$topics[$index]}\n");
            echo $reason->getMessage() . "\n";
        }
    )->wait();
} catch (Exception $e) {
    var_dump($e);
    exit($e->getMessage());
}

$f = fopen('cochrane_reviews.txt', 'w');
foreach ($summary as $topic => $reviews) {
    foreach ($reviews as $review) {
        fputs(
            $f,
            sprintf("%s|%s|%s|%s|%s\n", $review['url'], $topic, $review['title'], $review['authors'], $review['date'])
        );
    }
}
fclose($f);
exit("Done\n");
