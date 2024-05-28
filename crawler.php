#!/usr/bin/env php
<?php
namespace Sbehnfeldt\CochraneCrawler;

use Exception;
use PHPHtmlParser\Dom;


require_once 'vendor/autoload.php';


try {
    $crawler = new Crawler();
    $topics  = $crawler->getTopics();

    $summary = [];
    $dom     = new Dom();
    $it      = $topics->getIterator();
    echo("Found " . count($topics) . " topics....\n");
    $nTopic = 1;
    while ($it->valid()) {
        $dom->loadStr($it->current());
        $link   = $dom->find('a');
        $href   = $link->getAttribute('href');
        $button = $dom->find('button');
        $topic  = $button->innerHtml;
        echo("   Crawling topic {$nTopic}: \"{$topic}\" - ");
        $summary[$topic] = $crawler->getReviews($href, true);
        echo("\n");

        $nTopic++;
        $it->next();
    }
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
