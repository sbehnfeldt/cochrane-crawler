#!/usr/bin/env php
<?php
namespace Sbehnfeldt\CochraneCrawler;

require_once 'vendor/autoload.php';


$crawler = new Crawler();
$crawler->crawl('http://www.cochranelibrary.com/home/topic-and-review-group-list.html?page=topic');


$f = fopen('cochrane_reviews.txt', 'w');
foreach ($crawler->getNextSummary() as $s) {
    fputs($f, sprintf("%s|%s|%s|%s|%s\n", $s['url'], $s['topic'], $s['title'], $s['authors'], $s['date']));
}
fclose($f);
echo("Done\n");
exit(0);
