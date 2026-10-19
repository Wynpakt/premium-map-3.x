<?php

class BaseTask extends \Phalcon\Cli\Task 
{
    public $collmex_curl;

    public function countryMapper($country) {
        
        $country = trim($country);
        
        $countrys = [
            'DE' => 'Germany',
            'AT' => 'Austria',
            'CH' => 'Switzerland'
        ];
        
        if(isset($countrys[$country])) {
            return $countrys[$country];
        }
        
        return $country;
    }

    /**
     * Locate an address via the local Photon instance for that country.
     *
     * @param string $countryCode DE, AT or CH
     * @return array{lat:string,lng:string,street:string,postcode:string,city:string,country:string}|null|false
     *         Coords, null if not found or unreachable, false if the server asked us to back off
     */
    public function getCoordinates($street, $zip, $city, $countryCode){
        $urls = array();
        if (isset($this->config->photon)) {
            $urls = $this->config->photon->toArray();
        }
        if (isset($urls['url']) && $urls['url'] !== '' && empty($urls['default'])) {
            $urls['default'] = $urls['url'];
        }
        $base = PhotonGeocoder::baseUrl($urls, $countryCode);
        if ($base === null) {
            echo PHP_EOL . 'Kein Photon-Endpunkt für ' . $countryCode . PHP_EOL;
            return null;
        }

        $url = PhotonGeocoder::endpoint($base, $street, $zip, $city, $countryCode);
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
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        if ($status === 429) {
            echo PHP_EOL . 'Photon 429 für ' . $countryCode . PHP_EOL;
            return false;
        }

        if ($response === false || $status >= 400) {
            return null;
        }

        return PhotonGeocoder::parse(json_decode($response, true));
    }

    public function error($msg, $exit = true) {
        echo PHP_EOL . 'Fehler: ' . $msg . PHP_EOL;
        if($exit) {
            exit(0);
        }
    }

    public function collmexGet($param) {

        $this->collmex_curl = cURL_init("https://www.collmex.de/cgi-bin/cgi.exe?".$this->config->collmex->customer_id.",0,data_exchange");
        cURL_setopt($this->collmex_curl, CURLOPT_POST, 1);
        cURL_setopt($this->collmex_curl, CURLOPT_POSTFIELDS, "LOGIN;".$this->config->collmex->user.";".$this->config->collmex->password."\n".$param."\n");
        cURL_setopt($this->collmex_curl, CURLOPT_HTTPHEADER, Array("Content-Type: text/csv"));
        cURL_setopt($this->collmex_curl, CURLOPT_SSL_VERIFYPEER, FALSE);
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
        while ($line = fgetcsv($tmpHandle, 0, ';', '"')) {

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
