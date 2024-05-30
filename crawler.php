#!/usr/bin/env php
<?php
namespace Sbehnfeldt\CochraneCrawler;

require_once 'vendor/autoload.php';


$crawler = new Crawler();
$crawler->crawl('http://www.cochranelibrary.com/home/topic-and-review-group-list.html?page=topic');



//$f = fopen('cochrane_reviews.txt', 'w');
//foreach ($summary as $topic => $reviews) {
//    foreach ($reviews as $review) {
//        fputs(
//            $f,
//            sprintf("%s|%s|%s|%s|%s\n", $review['url'], $topic, $review['title'], $review['authors'], $review['date'])
//        );
//    }
//}
//fclose($f);
//exit("Done\n");
