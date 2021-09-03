<?php
// https://github.com/IchBinChrist/php-sitemap-generator

include dirname(__FILE__) . "/sitemap-generator.php";

$smg = new SitemapGenerator(include(dirname(__FILE__) . "/sitemap-config.php"));

// Run the generator
$smg->GenerateSitemap();