#!/usr/bin/env php
<?php
namespace Sbehnfeldt\CochraneCrawler;

require_once 'vendor/autoload.php';


// Set the default timezone (optional, but recommended)
date_default_timezone_set('America/New_York');
$start = time();
echo "Start: " . date('H:i:s', $start) . PHP_EOL;


$crawler = new Crawler();
$crawler->crawl('http://www.cochranelibrary.com/home/topic-and-review-group-list.html?page=topic');


$f = fopen('cochrane_reviews.txt', 'w');
foreach ($crawler->getNextSummary() as $s) {
    fputs($f, sprintf("%s|%s|%s|%s|%s\n", $s['url'], $s['topic'], $s['title'], $s['authors'], $s['date']));
}
fclose($f);
echo("Done\n");

$finish = time();
echo "Finish: " . date('H:i:s', $finish) . PHP_EOL;
echo "Elapsed: " . $finish - $start . " seconds" . PHP_EOL;
exit(0);