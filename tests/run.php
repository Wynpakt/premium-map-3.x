<?php

require __DIR__ . '/bootstrap.php';

test('marker query applies every selected country and product', function () {
    $sql = MarkerQuery::sql(array('DE', 'AT'), array('cola', 'bier', 'frohlunder'));
    assertContains("item.country='DE'", $sql);
    assertContains("item.country='AT'", $sql);
    assertContains("product.name='cola'", $sql);
    assertContains("product.name='frohlunder'", $sql);
    assertNotContains('holunder', $sql);
});

test('marker query with one country stays a single predicate', function () {
    $sql = MarkerQuery::sql(array('AT'), array('cola'));
    assertContains("item.country='AT'", $sql);
    assertNotContains(' OR ', $sql);
});

test('marker query with nothing selected matches nothing', function () {
    $sql = MarkerQuery::sql(array(), array('cola'));
    assertContains('1=0', $sql);
});

test('groups 16 and 93 stay off the map', function () {
    assertSame(true, MarkerQuery::isHidden('124,93'));
    assertSame(true, MarkerQuery::isHidden('16,8'));
    assertSame(false, MarkerQuery::isHidden('124,8,20'));
    assertSame(false, MarkerQuery::isHidden('160'));
});

test('marker query drops values that are not plain tokens', function () {
    $sql = MarkerQuery::sql(array("DE' OR 1=1 --"), array('cola'));
    assertNotContains('OR 1=1', $sql);
    assertContains('1=0', $sql);
});

test('default products use frohlunder', function () {
    $products = MarkerQuery::defaultProducts();
    assertSame('frohlunder', $products[2]);
    assertNotContains('holunder', implode(',', $products));
});

test('photon query puts country name on the free-text search', function () {
    assertSame(
        'Pariser Platz 1 10117 Berlin Germany',
        PhotonGeocoder::query('Pariser Platz 1', '10117', 'Berlin', 'DE')
    );
    assertSame('Wien Austria', PhotonGeocoder::query('', '', 'Wien', 'AT'));
});

test('photon endpoint is country specific', function () {
    $urls = array(
        'DE' => 'http://photon-de:2322',
        'AT' => 'http://photon-at:2322/',
    );
    assertSame('http://photon-at:2322', PhotonGeocoder::baseUrl($urls, 'at'));
    assertSame(null, PhotonGeocoder::baseUrl($urls, 'CH'));
    $endpoint = PhotonGeocoder::endpoint(PhotonGeocoder::baseUrl($urls, 'DE'), 'A 1', '10115', 'Berlin', 'DE');
    assertContains('/api?limit=1&lang=de&q=', $endpoint);
    assertContains('Germany', rawurldecode($endpoint));
});

test('photon refresh lock is a file on disk', function () {
    $missing = sys_get_temp_dir() . '/photon-refresh-missing-' . getmypid();
    $present = sys_get_temp_dir() . '/photon-refresh-present-' . getmypid();
    @unlink($missing);
    file_put_contents($present, "1\n");
    try {
        assertSame(false, PhotonIndexRefresh::isRefreshing($missing));
        assertSame(true, PhotonIndexRefresh::isRefreshing($present));
    } finally {
        @unlink($present);
    }
});

test('photon parse reads lon lat order from geojson', function () {
    $parsed = PhotonGeocoder::parse(array(
        'features' => array(
            array(
                'geometry' => array('coordinates' => array(13.4, 52.5)),
                'properties' => array(
                    'street' => 'Torstraße',
                    'housenumber' => '1',
                    'postcode' => '10119',
                    'city' => 'Berlin',
                    'country' => 'Germany',
                ),
            ),
        ),
    ));
    assertSame('52.5', $parsed['lat']);
    assertSame('13.4', $parsed['lng']);
    assertSame('Torstraße 1', $parsed['street']);
    assertSame(null, PhotonGeocoder::parse(array('features' => array())));
});

test('collmex row drops the phone at index 15 and keeps the fields the sync stores', function () {
    $row = array_fill(0, 32, '');
    $row[0] = 'CMXADR';
    $row[1] = '99';
    $row[5] = 'Ada';
    $row[6] = 'Lovelace';
    $row[7] = 'Premium Laden';
    $row[9] = 'Torstraße 1';
    $row[10] = '10119';
    $row[11] = 'Berlin';
    $row[14] = 'DE';
    $row[15] = '030123';
    $row[17] = 'ada@example.test';
    $row[28] = 'https://example.test';
    $row[31] = '124,8,20';

    $mapped = CollmexAddress::fromRow($row);
    assertSame(false, array_key_exists('phone', $mapped));
    assertSame('Torstraße 1', $mapped['street']);
    assertSame('10119', $mapped['zip']);
    assertSame('Berlin', $mapped['city']);
    assertSame('DE', $mapped['country']);
    assertSame('ada@example.test', $mapped['email']);
    assertSame('https://example.test', $mapped['web']);
    assertSame('124,8,20', $mapped['address_group']);
    assertSame(null, CollmexAddress::fromRow(array('CMXKND')));
});

test('address change keeps the old pin and marks geocoding pending', function () {
    $changed = AddressChange::addressDiffers('Alt 1', '10115', 'Berlin', 'Neu 2', '10115', 'Berlin');
    $next = AddressChange::nextGeoState($changed, '52.5', '13.4', 7, 0);
    assertSame('52.5', $next['lat']);
    assertSame('13.4', $next['lng']);
    assertSame(0, $next['geolocate_count']);
    assertSame(1, $next['geocode_pending']);

    $same = AddressChange::nextGeoState(false, '52.5', '13.4', 7, 0);
    assertSame(7, $same['geolocate_count']);
    assertSame(0, $same['geocode_pending']);
});

test('feedback rejects a long body and a sixth send in the hour', function () {
    assertSame(false, FeedbackGate::tooLong(str_repeat('a', 4000)));
    assertSame(true, FeedbackGate::tooLong(str_repeat('a', 4001)));
    $stamps = array(1000, 1001, 1002, 1003, 1004);
    list($ok, $kept) = FeedbackGate::allow($stamps, 1005);
    assertSame(false, $ok);
    assertSame(5, count($kept));
    list($ok, $kept) = FeedbackGate::allow(array(1000), 1000 + 3600);
    assertSame(true, $ok);
    assertSame(array(4600), $kept);
    $dir = sys_get_temp_dir() . '/landkarte-feedback-test-' . getmypid();
    mkdir($dir);
    try {
        for ($i = 0; $i < 5; $i++) {
            assertSame(true, FeedbackGate::remember('203.0.113.5', 1000 + $i, $dir));
        }
        assertSame(false, FeedbackGate::remember('203.0.113.5', 1005, $dir));
    } finally {
        $path = $dir . '/landkarte-feedback-' . hash('sha256', '203.0.113.5');
        @unlink($path);
        @rmdir($dir);
    }
});

test('feedback contacts are read from the app config file', function () {
    $path = FeedbackRouter::contactsPath();
    assertSame(dirname(__DIR__) . '/app/config/feedback.txt', $path);
    assertSame(true, is_readable($path));
});

test('feedback router matches country and zip prefix', function () {
    $contacts = FeedbackRouter::parseLines("DE;10;max@example.test\nAT;1;doerte@example.test\n");
    assertSame('max@example.test', FeedbackRouter::route($contacts, 'DE', '10115'));
    assertSame('doerte@example.test', FeedbackRouter::route($contacts, 'at', '1010'));
    assertSame(FeedbackRouter::DEFAULT_EMAIL, FeedbackRouter::route($contacts, 'CH', '8000'));
});

test('feedback file sends every country to the contact inbox', function () {
    $contacts = FeedbackRouter::parseLines(file_get_contents(dirname(__DIR__) . '/app/config/feedback.txt'));
    $inbox = 'kontakt@premium-kollektiv.de';
    assertSame($inbox, FeedbackRouter::route($contacts, 'DE', '10115'));
    assertSame($inbox, FeedbackRouter::route($contacts, 'DE', '50667'));
    assertSame($inbox, FeedbackRouter::route($contacts, 'DE', '80331'));
    assertSame($inbox, FeedbackRouter::route($contacts, 'DE', ''));
    assertSame($inbox, FeedbackRouter::route($contacts, 'AT', '1010'));
    assertSame($inbox, FeedbackRouter::route($contacts, 'CH', '8000'));
    assertSame($inbox, FeedbackRouter::route($contacts, 'NL', '1012'));
    assertSame($inbox, FeedbackRouter::route($contacts, '', '10115'));
});

test('rejected recipient falls back to the contact inbox', function () {
    $rejected = 'Expected response code "250/251/252" but got code "550", with message "550 5.1.1 <jane@premium-kollektiv.de>: Recipient address rejected: User unknown".';
    assertSame(true, MailFallback::isRecipientRejected(new Exception($rejected)));
    assertSame(false, MailFallback::isRecipientRejected(new Exception('Connection could not be established.')));
    assertSame(false, MailFallback::isRecipientRejected(new Exception('Expected response code "250" but got code "550", with message "550 Message content rejected".')));
    assertSame(MailFallback::ADDRESS, MailFallback::replacement('jane@premium-kollektiv.de'));
    assertSame(null, MailFallback::replacement(MailFallback::ADDRESS));
    assertSame(null, MailFallback::replacement('Kontakt@premium-kollektiv.de'));
    assertContains('jane@premium-kollektiv.de', MailFallback::notice('jane@premium-kollektiv.de'));
});

test('smtp from config becomes one address', function () {
    $parsed = MailAddress::parse(array('landkarte@premium-cola.de' => 'Premium Landkarte'));
    assertSame('landkarte@premium-cola.de', $parsed['email']);
    assertSame('Premium Landkarte', $parsed['name']);
    assertSame('ada@example.test', MailAddress::parse('ada@example.test')['email']);
    assertSame(null, MailAddress::parse(''));

    $config = new class {
        public function toArray()
        {
            return array('landkarte@premium-cola.de' => 'Premium Landkarte');
        }
    };
    $fromConfig = MailAddress::parse($config);
    if (!is_array($fromConfig)) {
        throw new Exception('expected address from config object, got ' . var_export($fromConfig, true));
    }
    assertSame('landkarte@premium-cola.de', $fromConfig['email']);
    assertSame('Premium Landkarte', $fromConfig['name']);
});

test('search keyword is a bound like pattern', function () {
    assertSame('%berlin%', SearchQuery::like('berlin'));
    assertSame('%100 berlin%', SearchQuery::like('100% berlin_'));
    assertContains('LIKE :q', SearchQuery::sql());
    assertContains('item.city LIKE :q', SearchQuery::sql());
    assertContains('LIMIT 20', SearchQuery::sql());
    assertNotContains('REPLACE("', SearchQuery::sql());
});

test('duplicate finder groups the same address and the same pin', function () {
    $rows = array(
        array('id' => 1, 'name' => 'A', 'street' => 'Torstraße 1', 'zip' => '10119', 'city' => 'Berlin', 'lat' => '52.50001', 'lng' => '13.40001'),
        array('id' => 2, 'name' => 'B', 'street' => 'torstraße  1', 'zip' => '10119', 'city' => 'berlin', 'lat' => '48.1', 'lng' => '11.5'),
        array('id' => 3, 'name' => 'C', 'street' => 'Andere 9', 'zip' => '80331', 'city' => 'München', 'lat' => '52.50004', 'lng' => '13.40004'),
    );
    $groups = DuplicateFinder::groups($rows);
    assertSame(2, count($groups['address']['torstraße 1|10119|berlin']));
    assertSame(2, count($groups['point']['52.5,13.4']));
});

test('item list rejects a filter that is not an array', function () {
    assertSame(true, ListQuery::rejected(array('types' => 'laeden')));
    assertSame(true, ListQuery::rejected(array('types' => array('laeden'), 'countries' => 'DE')));
    assertSame(true, ListQuery::rejected(array('products' => 'cola')));
    assertSame(false, ListQuery::rejected(array('types' => array('laeden'), 'countries' => array('DE'))));
    assertSame(false, ListQuery::rejected(array()));
});

test('photon refresh keeps the installed index when the new one does not fit beside it', function () {
    $script = dirname(__DIR__) . '/docs/deploy/photon/refresh-indexes.sh';
    exec('bash ' . escapeshellarg($script) . ' --self-check', $out, $code);
    assertSame(0, $code);
});

test('public router does not install phalcon default routes', function () {
    $src = file_get_contents(dirname(__DIR__) . '/app/config/router.php');
    assertContains('Router(false)', $src);
});

test('public front script does not print exception text', function () {
    $src = file_get_contents(dirname(__DIR__) . '/public/index.php');
    assertContains("display_errors', '0'", $src);
    assertContains('http_response_code(404)', $src);
    assertContains('http_response_code(500)', $src);
    assertNotContains('echo $e->getMessage()', $src);
});

test('an empty or invalid collmex email clears the stored address', function () {
    assertSame('a@b.de', ShopEmail::stored('a@b.de'));
    assertSame('a@b.de', ShopEmail::stored(' a@b.de, c@d.de '));
    assertSame('', ShopEmail::stored(''));
    assertSame('', ShopEmail::stored('not-an-email'));
});

test('a shop leaves the map once when it is no longer in group 124', function () {
    $kept = SyncCleanup::keptIds(array(
        array('address_id' => 5, 'collmex_address_groups' => '8,124'),
        array('address_id' => 5, 'collmex_address_groups' => '8'),
        array('address_id' => 6, 'collmex_address_groups' => '8'),
    ));
    assertSame(array('5' => true), $kept);
    assertSame(array(), SyncCleanup::keptIds(false));
});

test('an empty collmex fetch is a failed sync', function () {
    assertSame(true, SyncRun::emptyFetch(false));
    assertSame(true, SyncRun::emptyFetch(array()));
    assertSame(false, SyncRun::emptyFetch(array(array('address_id' => '1'))));
});

test('a photon outage does not count as a miss', function () {
    assertSame(false, PhotonGeocoder::fromHttp(false, 0));
    assertSame(false, PhotonGeocoder::fromHttp('down', 500));
    assertSame(false, PhotonGeocoder::fromHttp('slow', 429));
    assertSame(null, PhotonGeocoder::fromHttp('{"features":[]}', 200));
});

test('street compare folds straße and ignores punctuation', function () {
    assertSame(false, StreetCompare::differs('Torstraße 1', 'Torstr. 1'));
    assertSame(true, StreetCompare::differs('Torstraße 1', 'Linienstraße 1'));
    assertSame(false, StreetCompare::differs('Torstraße 1', ''));
});

finished();
