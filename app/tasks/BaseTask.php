<?php

class BaseTask extends \Phalcon\Cli\Task 
{
    public $collmex_curl;

    /**
     * @param string $countryCode DE, AT or CH
     * @return string|null
     */
    public function photonBase($countryCode)
    {
        $urls = array();
        if (isset($this->config->photon)) {
            $urls = $this->config->photon->toArray();
        }
        if (isset($urls['url']) && $urls['url'] !== '' && empty($urls['default'])) {
            $urls['default'] = $urls['url'];
        }

        return PhotonGeocoder::baseUrl($urls, $countryCode);
    }

    /**
     * @return array|false parsed features, false if Photon did not answer
     */
    public function photonFeatures($url)
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Premium-Landkarte/3.x (landkarte@premium-cola.de)\r\nAccept: application/json\r\n",
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $status = 0;
        $headers = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?: array())
            : array();
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) {
            $status = (int) $m[1];
        }
        if ($status === 429) {
            echo PHP_EOL . 'Photon 429' . PHP_EOL;
        }

        return PhotonGeocoder::featuresFromHttp($response, $status);
    }

    /**
     * Locate an address via the local Photon instance for that country.
     * A requested PLZ is searched around that postcode, and a hit in another
     * postcode is refused.
     *
     * @param string $countryCode DE, AT or CH
     * @return array|null|false coords, null if Photon found nothing, false if it did not answer
     */
    public function getCoordinates($street, $zip, $city, $countryCode){
        $base = $this->photonBase($countryCode);
        if ($base === null) {
            echo PHP_EOL . 'Kein Photon-Endpunkt für ' . $countryCode . PHP_EOL;
            // shortcut: no endpoint stops this run, same as an outage
            return false;
        }

        $zip = trim((string) $zip);
        if ($zip !== '') {
            $anchorFeatures = $this->photonFeatures(
                PhotonGeocoder::endpoint($base, '', $zip, $city, $countryCode)
            );
            if ($anchorFeatures === false) {
                return false;
            }
            $anchor = PhotonGeocoder::anchor($anchorFeatures, $zip);
            if ($anchor) {
                foreach (PhotonGeocoder::bboxRadii() as $radius) {
                    $features = $this->photonFeatures(PhotonGeocoder::endpoint(
                        $base,
                        $street,
                        '',
                        $city,
                        $countryCode,
                        PhotonGeocoder::bbox($anchor['lat'], $anchor['lng'], $radius)
                    ));
                    if ($features === false) {
                        return false;
                    }
                    $chosen = PhotonGeocoder::choose($features, $street, $zip);
                    if ($chosen) {
                        return $chosen;
                    }
                }
            }
        }

        $features = $this->photonFeatures(PhotonGeocoder::endpoint($base, $street, $zip, $city, $countryCode));
        if ($features === false) {
            return false;
        }

        return PhotonGeocoder::choose($features, $street, $zip);
    }

    public function error($msg, $exit = true) {
        echo PHP_EOL . 'Fehler: ' . $msg . PHP_EOL;
        if($exit) {
            exit(1);
        }
    }

    public function collmexGet($param) {

        $this->collmex_curl = cURL_init("https://www.collmex.de/cgi-bin/cgi.exe?".$this->config->collmex->customer_id.",0,data_exchange");
        cURL_setopt($this->collmex_curl, CURLOPT_POST, 1);
        cURL_setopt($this->collmex_curl, CURLOPT_POSTFIELDS, "LOGIN;".$this->config->collmex->user.";".$this->config->collmex->password."\n".$param."\n");
        cURL_setopt($this->collmex_curl, CURLOPT_HTTPHEADER, Array("Content-Type: text/csv"));
        cURL_setopt($this->collmex_curl, CURLOPT_RETURNTRANSFER, 1);
        $csv = curl_exec($this->collmex_curl);

        cURL_close($this->collmex_curl);

        return $this->collmexCsvParse($csv);
    }

    public function collmexAddressGet() {

        // dev caching
        /*
        if(file_exists('/tmp/collmexAddressGet.tmp')) {
            echo "load cached items\n";
            $data = unserialize(file_get_contents('/tmp/collmexAddressGet.tmp'));

            return $data;
        }
        */

        if($data = $this->collmexGet('ADDRESS_GET')){
            $out = [];
            foreach ($data as $d) {
                $mapped = CollmexAddress::fromRow($d);
                if ($mapped === null) {
                    continue;
                }
                $out[] = $mapped;
            }
            //file_put_contents('/tmp/collmexAddressGet.tmp',serialize($out));
            return $out;
        }
        return false;
    }

    public function collmexCsvParse($csv)
    {
        $csv = iconv('Windows-1252', 'UTF-8', $csv);
        $tmpHandle = tmpfile();
        fwrite($tmpHandle, $csv);
        rewind($tmpHandle);
        $data = [];
        // Explicit backslash keeps the pre-PHP-8.4 parse and stays out of sync.log.
        while ($line = fgetcsv($tmpHandle, 0, ';', '"', '\\')) {

            $data[] = $line;
        }
        fclose($tmpHandle);



        return $data;
    }

    public function addhttp($url) {
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "http://" . $url;
        }
        return $url;
    }

}
