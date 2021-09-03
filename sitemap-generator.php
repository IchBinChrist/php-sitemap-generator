<?php

class SitemapGenerator
{
	// Config file with crawler/sitemap options
	private $config;

	// Array containing all scanned pages
	private $scanned;

	// Array containing all completed pages
	private $completed;

	// The base of the given site url
	// EXAMPLE: https://student-laptop.nl
	private $site_url_base;

	// File where sitemap is written to.
	private $sitemap_file;

	//Timestamp of the start time
	private $start_time;

	// Has found a new page
	private $is_changed;

	// Constructor sets the given file for internal use
	public function __construct($conf)
	{
		// Setup class variables using the config
		$this->config = $conf;
		$this->scanned = [];
		$this->completed = [];
		$this->site_url_base = parse_url($this->config['SITE_URL'])['scheme'] . "://" . parse_url($this->config['SITE_URL'])['host'];
		$this->start_time = time();
		$this->is_changed = false;
		
	}

	public function GenerateSitemap()
	{
		$this->addTempSitemap();
		
		
		// Call the recursive crawl function with the start url.
		$this->crawlPage($this->config['SITE_URL']);

		// Generate a temp-sitemap with the completed pages.
		if($this->config['SAVE_TEMP']!='' && $this->config['TIME_LIMIT']!=-1 && $this->start_time < (time() - $this->config['TIME_LIMIT']) ){
			echo count($this->scanned).' (';
			$this->sitemap_file = fopen($this->config['SAVE_TEMP'], "w");
			$this->generateFile($this->completed);
			echo ')';

			if( !$this->is_changed ){
				//rename($this->config['SAVE_TEMP'], $this->config['SAVE_LOC']);
			}
		}else{
			$this->sitemap_file = fopen($this->config['SAVE_LOC'], "w");
			// Generate a sitemap with the scanned pages.
			$this->generateFile($this->scanned);
			if($this->config['SAVE_TEMP']!='' && file_exists($this->config['SAVE_TEMP'])){
				unlink($this->config['SAVE_TEMP']);
			}
		}


	}

	// Get the html content of a page and return it as a dom object
	private function getHtml($url)
	{
		// Get html from the given page
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_TIMEOUT, $this->config['CURLOPT_TIMEOUT']);
		$html = curl_exec($curl);
		curl_close($curl);

		//Load the html and store it into a DOM object
		$dom = new DOMDocument();
		@$dom->loadHTML($html);

		return $dom;
	}

	// Read and add the last temp-sitemap
	private function addTempSitemap()
	{
		if($this->config['SAVE_TEMP']!='' && file_exists($this->config['SAVE_TEMP'])){
			$xml = simplexml_load_file($this->config['SAVE_TEMP']);
			
			foreach($xml->url as $urlItem ) { 
				array_push($this->completed, $urlItem->loc);
				array_push($this->scanned, $urlItem->loc);
				//echo $urlItem->loc.'<br>';
			}
		}
	}

	// Recursive function that crawls a page's anchor tags and store them in the scanned array.
	private function crawlPage($page_url)
	{
		$url = filter_var($page_url, FILTER_SANITIZE_URL);

		// Check if the url is invalid or if the page is already scanned;
		if (in_array($url, $this->scanned) || !filter_var($page_url, FILTER_VALIDATE_URL)) {
			return;
		}

		// Check match PREG_MATCH
		if(!($this->config['PREG_MATCH']=='' || preg_match_all($this->config['PREG_MATCH'], $page_url) )){
			return;
		}

		// Add the page url to the scanned array
		array_push($this->scanned, $page_url);
		$this->is_changed = true;

		// Check the time limit
		if($this->config['TIME_LIMIT']!=-1 && $this->start_time < (time()-$this->config['TIME_LIMIT'])) {
			return;
		}

		// Get the html content from the 
		$html = $this->getHtml($url);
		$anchors = $html->getElementsByTagName('a');

		// Loop through all anchor tags on the page
		foreach ($anchors as $a) {
			$next_url = $a->getAttribute('href');
			// Skip email and Phone
			if(strpos( $next_url, 'tel:' ) === 0  || strpos( $next_url, 'mailto:' ) === 0 ) {
				continue;
			}

			// Check if there is a anchor ID set in the config.
			if ($this->config['CRAWL_ANCHORS_WITH_ID'] != "") {
				// Check if the id is set and matches the config setting, else it will move on to the next anchor
				if ($a->getAttribute('id') != "" || $a->getAttribute('id') == $this->config['CRAWL_ANCHORS_WITH_ID']) {
					continue;
				}
			}

			// Split page url into base and extra parameters
			$base_page_url = explode("?", $page_url)[0];

			if (!$this->config['ALLOW_ELEMENT_LINKS']) {
				// Skip the url if it starts with a # or is equal to root.
				if (substr($next_url, 0, 1) == "#" || $next_url == "/") {
					continue;
				}
			}

			// Check if the given url is external, if yes it will skip the iteration
			// This code will only run if you set ALLOW_EXTERNAL_LINKS to false in the config.
			if (!$this->config['ALLOW_EXTERNAL_LINKS']) {
				$parsed_url = parse_url($next_url);
				if (isset($parsed_url['host'])) {
					if ($parsed_url['host'] != parse_url($this->config['SITE_URL'])['host']) {
						continue;
					}
				}
			}
			
			// Check if the link is absolute or relative.
			if (substr($next_url, 0, 7) != "http://" && substr($next_url, 0, 8) != "https://") {
				$next_url = $this->convertRelativeToAbsolute($base_page_url, $next_url);
			}

			// Check if the next link contains any of the pages to skip. If true, the loop will move on to the next iteration.
			$found = false;
			foreach ($this->config['KEYWORDS_TO_SKIP'] as $skip) {
				if (strpos($next_url, $skip) || $next_url === $skip) {
					$found = true;
				}
			}

			// Call the function again with the new URL
			if (!$found) {
				$this->crawlPage($next_url);
			}
		}
		
		if($this->config['TIME_LIMIT']!=-1 && $this->start_time < (time()-$this->config['TIME_LIMIT'])) {
			return;
		}

		array_push($this->completed, $page_url);
	}

	// Convert a relative link to a absolute link
	// Example: Relative /articles
	//			Absolute https://student-laptop.nl/articles
	private function convertRelativeToAbsolute($page_base_url, $link)
	{
		$first_character = substr($link, 0, 1);
		if ($first_character == "?" || $first_character == "#") {
			return $page_base_url . $link;
		} else if ($first_character != "/") {
			return $this->site_url_base . "/" . $link;
		} else {
			return $this->site_url_base . $link;
		}
	}

	// Function to generate a Sitemap with the given pages array where the script has run through
	private function generateFile($pages)
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
		<!-- ' . count($pages) . ' total pages-->
		<!-- PHP-sitemap-generator by https://github.com/tristangoossens -->';


		// Print the amount of pages
		echo count($pages);

		foreach ($pages as $page) {
			$xml .= "<url><loc>" . $page . "</loc>
            <lastmod>" . $this->config['LAST_UPDATED'] . "</lastmod>
            <changefreq>" . $this->config['CHANGE_FREQUENCY'] . "</changefreq>
            <priority>" . $this->config['PRIORITY'] . "</priority></url>";
		}

		$xml .= "</urlset>";
		$xml = str_replace('&', '&amp;', $xml);

		// Format string to XML
		$dom = new DOMDocument;
		$dom->preserveWhiteSpace = FALSE;
		$dom->loadXML($xml);
		$dom->formatOutput = TRUE;

		// Write XML to file and close it
		fwrite($this->sitemap_file, $dom->saveXML());
		fclose($this->sitemap_file);
	}
}
