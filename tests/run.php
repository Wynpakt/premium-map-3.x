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
    assertContains('/api?limit=50&lang=de&q=', $endpoint);
    assertContains('Germany', rawurldecode($endpoint));
    $boxed = PhotonGeocoder::endpoint(
        PhotonGeocoder::baseUrl($urls, 'DE'),
        'Ritterstraße 9',
        '',
        'Berlin',
        'DE',
        PhotonGeocoder::bbox(52.50249, 13.40111, 0.04)
    );
    assertContains('&bbox=13.36111,52.46249,13.44111,52.54249', $boxed);
    assertNotContains('10969', rawurldecode($boxed));
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
    assertSame('1', $parsed['housenumber']);
    assertSame(null, PhotonGeocoder::parse(array('features' => array())));
});

test('a hit in another postcode is refused, a house-number range in the right one is kept', function () {
    $lichterfelde = array(
        'lat' => '52.4327594',
        'lng' => '13.3201403',
        'street' => 'Ritterstraße 9',
        'housenumber' => '9',
        'postcode' => '12207',
        'city' => 'Berlin',
        'country' => 'Germany',
        'name' => '',
    );
    $kreuzberg = array(
        'lat' => '52.5013605',
        'lng' => '13.4119760',
        'street' => 'Ritterstraße 9-10',
        'housenumber' => '9-10',
        'postcode' => '10969',
        'city' => 'Berlin',
        'country' => 'Germany',
        'name' => 'Pelikan-Haus',
    );
    $otherStreet = array(
        'lat' => '52.49',
        'lng' => '13.40',
        'street' => 'Bergmannstraße 9',
        'housenumber' => '9',
        'postcode' => '10969',
        'city' => 'Berlin',
        'country' => 'Germany',
        'name' => '',
    );

    $chosen = PhotonGeocoder::choose(array($lichterfelde, $otherStreet, $kreuzberg), 'Ritterstraße 9', 'D-10969');
    assertSame('52.5013605', $chosen['lat']);
    assertSame(null, PhotonGeocoder::choose(array($lichterfelde), 'Ritterstraße 9', '10969'));
    assertSame(null, PhotonGeocoder::choose(array($otherStreet), 'Ritterstraße 9', '10969'));
    assertSame('52.4327594', PhotonGeocoder::choose(array($lichterfelde, $kreuzberg), 'Ritterstraße 9', '')['lat']);
    assertSame(true, PhotonGeocoder::meters('52.4327594', '13.3201403', '52.5013605', '13.4119760') > 8000);
});

test('postcode anchor prefers the area named like the zip', function () {
    $house = array('postcode' => '10969', 'name' => 'Berlinische Galerie', 'lat' => '52.50', 'lng' => '13.39');
    $area = array('postcode' => '10969', 'name' => '10969', 'lat' => '52.50249', 'lng' => '13.40111');
    $anchor = PhotonGeocoder::anchor(array($house, $area), '10969');
    assertSame('13.40111', $anchor['lng']);
    assertSame(null, PhotonGeocoder::anchor(array($house), '12207'));
});

test('collmex row keeps the phone so only an internal host can store it', function () {
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
    assertSame('030123', $mapped['phone']);
    assertSame(false, MapAudience::storesPhone('public'));
    assertSame(true, MapAudience::storesPhone('internal'));
    assertSame('Torstraße 1', $mapped['street']);
    assertSame('10119', $mapped['zip']);
    assertSame('Berlin', $mapped['city']);
    assertSame('DE', $mapped['country']);
    assertSame('ada@example.test', $mapped['email']);
    assertSame('https://example.test', $mapped['web']);
    assertSame('124,8,20', $mapped['address_group']);
    assertSame(null, CollmexAddress::fromRow(array('CMXKND')));
});

test('collmex row keeps the staff fields an internal popup needs', function () {
    $row = array_fill(0, 38, '');
    $row[0] = 'CMXADR';
    $row[1] = '99';
    $row[2] = '0';
    $row[5] = 'Ada';
    $row[6] = 'Lovelace';
    $row[7] = 'Premium Laden';
    $row[8] = 'Einkauf';
    $row[12] = 'nur vormittags';
    $row[13] = '1';
    $row[25] = '1';
    $row[26] = '0171';

    $mapped = CollmexAddress::fromRow($row);
    assertSame('Einkauf', $mapped['department']);
    assertSame('Ada', $mapped['firstname']);
    assertSame('Lovelace', $mapped['surname']);
    assertSame('nur vormittags', $mapped['note']);
    assertSame('1', $mapped['inactive']);
    assertSame('1', $mapped['no_mailings']);
    assertSame('0171', $mapped['phone2']);
    assertSame(false, CollmexAddress::isContact($mapped));
});

test('a contact row belongs to the shop address and keeps its own number', function () {
    $row = array_fill(0, 38, '');
    $row[0] = 'CMXADR';
    $row[1] = '99';
    $row[2] = '4';
    $row[5] = 'Grace';
    $row[6] = 'Hopper';
    $row[8] = 'Einkauf';
    $row[15] = '030111';
    $row[17] = 'grace@example.test';
    $row[26] = '0171999';
    $row[37] = '501';

    $mapped = CollmexAddress::fromRow($row);
    assertSame(true, CollmexAddress::isContact($mapped));
    $contact = CollmexAddress::contact($mapped);
    assertSame(99, $contact['parent_id']);
    assertSame(501, $contact['collmex_id']);
    assertSame('Grace Hopper', $contact['name']);
    assertSame('Einkauf', $contact['department']);
    assertSame('030111', $contact['phone']);
    assertSame('0171999', $contact['phone2']);
    assertSame('grace@example.test', $contact['email']);

    $book = CollmexAddress::contactsFor(array(
        $mapped,
        array('address_type' => '0', 'address_id' => '99'),
    ));
    assertSame('Grace Hopper', $book[99][0]['name']);
    $shops = CollmexAddress::shops(array(
        array('address_type' => '0', 'address_id' => '5'),
        $mapped,
    ));
    assertSame(1, count($shops));
    assertSame('5', $shops[0]['address_id']);
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

test('an internal host keeps an address that is not in group 124', function () {
    assertSame(false, MapAudience::storesAddress('public', array('8')));
    assertSame(true, MapAudience::storesAddress('public', array('124', '8')));
    assertSame(true, MapAudience::storesAddress('internal', array('8')));
    assertSame(true, MapAudience::storesAddress('', array('124')));
    assertSame(false, MapAudience::keepsWithoutOfferType('public'));
    assertSame(true, MapAudience::keepsWithoutOfferType('internal'));
    assertSame(true, MapAudience::clearsStoredPhones('public'));
    assertSame(false, MapAudience::clearsStoredPhones('internal'));
    $kept = SyncCleanup::keptIds(array(
        array('address_id' => 7, 'collmex_address_groups' => '8'),
    ), 'internal');
    assertSame(array('7' => true), $kept);
    $text = "<?php\nreturn array(\n    'collmex' => array(),\n);\n";
    $patched = MapAudience::patchConfigText($text, 'internal');
    assertContains("'audience' => 'internal'", $patched);
    $again = MapAudience::patchConfigText($patched, 'public');
    assertContains("'audience' => 'public'", $again);
    assertNotContains("'audience' => 'internal'", $again);
    $sync = file_get_contents(dirname(__DIR__) . '/app/tasks/UpdateTask.php');
    assertContains('MapAudience::storesAddress', $sync);
    assertContains('MapAudience::storesPhone', $sync);
    assertContains('MapAudience::keepsWithoutOfferType', $sync);
});

test('installer self-check locks an internal map and leaves a public map open', function () {
    $script = dirname(__DIR__) . '/docs/deploy/install-ubuntu-26.04.sh';
    exec('bash ' . escapeshellarg($script) . ' --self-check', $out, $code);
    assertSame(0, $code);
    $api = file_get_contents(dirname(__DIR__) . '/docs/deploy/apache-api.conf');
    $map = file_get_contents(dirname(__DIR__) . '/docs/deploy/apache-map.conf');
    assertContains('__AUTH__', $api);
    assertContains('__AUTH__', $map);
    assertContains('__API_PROXY__', $map);
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

test('the internal map still shows groups 16 and 93', function () {
    assertSame(true, MarkerQuery::isHiddenFor('public', '16,8'));
    assertSame(false, MarkerQuery::isHiddenFor('internal', '16,8'));
    assertSame(false, MarkerQuery::isHiddenFor('internal', '124,93'));
    assertSame(false, MapAudience::requiresOfferAndProduct('internal'));
    assertSame(true, MapAudience::requiresOfferAndProduct('public'));
});

test('a german postcode resolves to its state, a border code to both', function () {
    assertSame(array('BE'), Bundesland::fromZip('10115'));
    assertSame(array('BY'), Bundesland::fromZip('80331'));
    assertSame(array('SN'), Bundesland::fromZip('01067'));
    assertSame(array('NI'), Bundesland::fromZip('48455'));
    assertSame(array('NW'), Bundesland::fromZip('48143'));
    $border = Bundesland::fromZip('07919');
    assertSame(true, in_array('SN', $border, true));
    assertSame(true, in_array('TH', $border, true));
    assertSame(array(), Bundesland::fromZip('101'));
    assertSame(array(), Bundesland::fromZip(''));
    assertSame('', Bundesland::sql(Bundesland::codes()));
    $berlin = Bundesland::sql(array('BE'));
    assertContains("item.country='DE'", $berlin);
    assertContains('BETWEEN 10001 AND 14330', $berlin);
    assertSame('1=0', Bundesland::sql(array('XX')));
});

test('internal marker sql keeps addresses without a role or a product', function () {
    $sql = InternalFilter::sql(array(
        'types' => array('laeden', 'haendler', 'sprecher', 'webshop', 'ohne'),
        'countries' => array('DE', 'AT', 'CH', 'OTHER'),
        'products' => array('cola', 'bier', 'frohlunder', 'muntermate', 'ohne'),
        'status' => array('aktuell', 'ehemalig'),
        'visibility' => array('oeffentlich', 'verborgen'),
        'states' => Bundesland::codes(),
    ));
    assertContains('LEFT JOIN item_has_offertype', $sql);
    assertNotContains('FIND_IN_SET', $sql);
    assertNotContains('item_has_product', $sql);
    assertNotContains("item.country=", $sql);
    assertNotContains('1=0', $sql);
});

test('internal filters narrow status, visibility, role, product and state', function () {
    $former = InternalFilter::sql(array(
        'types' => array('laeden'),
        'status' => array('ehemalig'),
        'visibility' => array('verborgen'),
        'products' => array('ohne'),
        'states' => array('BE'),
    ));
    assertContains('ho.offertype_id=1', $former);
    assertContains("FIND_IN_SET('16'", $former);
    assertContains("FIND_IN_SET('93'", $former);
    assertContains('NOT EXISTS', $former);
    assertContains('BETWEEN 10001 AND 14330', $former);

    $current = InternalFilter::sql(array(
        'types' => array('ohne'),
        'status' => array('aktuell'),
        'products' => array('cola', 'ohne'),
        'countries' => array('OTHER'),
    ));
    assertContains('ho.offertype_id IS NULL', $current);
    assertContains("FIND_IN_SET('16', item.collmex_address_groups) = 0", $current);
    assertContains("p.name='cola'", $current);
    assertContains('NOT EXISTS', $current);
    assertContains("item.country NOT IN ('DE','AT','CH')", $current);
    assertNotContains("item.country='DE'", $current);
});

test('an empty internal selection matches nothing and a bad token is dropped', function () {
    $empty = InternalFilter::sql(array(
        'types' => array(''),
        'status' => array('nope'),
        'states' => array("BE' OR 1=1"),
    ));
    assertContains('1=0', $empty);
    assertNotContains('OR 1=1', $empty);
    assertSame(null, InternalFilter::sql(array()));
});

test('internal group match does not treat 160 as former', function () {
    assertSame(true, AddressGroup::contains('160,16', '16'));
    assertSame(false, AddressGroup::contains('160', '16'));
    assertSame(false, AddressGroup::contains('124', '16'));
});

test('internal address fetch asks collmex for contacts and inactive rows', function () {
    assertSame(array('ADDRESS_GET'), explode(';', CollmexFetch::addresses('public')));
    $internal = explode(';', CollmexFetch::addresses('internal'));
    assertSame('ADDRESS_GET', $internal[0]);
    assertSame('1', $internal[9]);
    assertSame('', $internal[10]);
    assertSame('1', $internal[11]);
    assertSame(array('ADDRESS_GROUPS_GET'), explode(';', CollmexFetch::groups()));
});

test('address group row keeps the collmex number and name', function () {
    assertSame(
        array('id' => 8, 'name' => 'Laden'),
        CollmexAddressGroup::fromRow(array('ADRGRP', '8', 'Laden'))
    );
    assertSame(null, CollmexAddressGroup::fromRow(array('CMXADR', '8', 'Laden')));
    assertSame(null, CollmexAddressGroup::fromRow(array('ADRGRP', '0', 'Laden')));
});

test('address group labels prefer collmex names and otherwise a known word or the number', function () {
    assertSame(array('Laden', '77'), AddressGroup::labels('8,77', array()));
    assertSame(array('Markt', '77'), AddressGroup::labels('8,77', array('8' => 'Markt')));
    assertSame(array(), AddressGroup::labels('', array()));
});

test('public detail omits staff fields and hides a speaker street', function () {
    $public = ShopDetail::payload('public', shopDetailFixture(), array('8' => 'Laden'));
    assertSame('', $public['street']);
    assertSame(false, array_key_exists('phone', $public));
    assertSame(false, array_key_exists('note', $public));
    assertSame(false, array_key_exists('contacts', $public));
    assertSame(false, array_key_exists('groups', $public));
});

test('internal detail shows phone, person, groups, note and contacts', function () {
    $detail = ShopDetail::payload('internal', shopDetailFixture(), array('8' => 'Aus Collmex'));
    assertSame('Torstraße 1', $detail['street']);
    assertSame('030123', $detail['phone']);
    assertSame('0171', $detail['phone2']);
    assertSame('Ada Lovelace', $detail['person']);
    assertSame('Einkauf', $detail['department']);
    assertSame('nur vormittags', $detail['note']);
    assertSame(true, $detail['inactive']);
    assertSame(true, $detail['no_mailings']);
    assertSame(99, $detail['collmex_id']);
    assertSame('Aus Collmex', $detail['groups'][0]);
    assertSame('ehemalige Kunden', $detail['groups'][1]);
    assertSame('Grace Hopper', $detail['contacts'][0]['name']);
    assertSame('030111', $detail['contacts'][0]['phone']);
});

test('internal detail skips a person line that repeats the shop name', function () {
    $shop = shopDetailFixture();
    $shop['name'] = 'Ada Lovelace';
    $shop['speaker_only'] = false;
    assertSame('', ShopDetail::payload('internal', $shop, array())['person']);
});

function shopDetailFixture()
{
    return array(
        'id' => 7,
        'name' => 'Premium Laden',
        'street' => 'Torstraße 1',
        'city' => 'Berlin',
        'zip' => '10119',
        'web' => 'https://example.test',
        'email' => 'ada@example.test',
        'products' => array('Cola'),
        'offertypes' => array('Sprecher'),
        'speaker_only' => true,
        'phone' => '030123',
        'phone2' => '0171',
        'department' => 'Einkauf',
        'firstname' => 'Ada',
        'surname' => 'Lovelace',
        'note' => 'nur vormittags',
        'inactive' => '1',
        'no_mailings' => '1',
        'collmex_address_id' => '99',
        'collmex_address_groups' => '8,16',
        'contacts' => array(array(
            'name' => 'Grace Hopper',
            'department' => 'Einkauf',
            'phone' => '030111',
            'phone2' => '',
            'email' => 'grace@example.test',
        )),
    );
}

test('item list rejects an internal filter that is not an array', function () {
    assertSame(true, ListQuery::rejected(array('status' => 'ehemalig')));
    assertSame(true, ListQuery::rejected(array('states' => 'BE')));
    assertSame(true, ListQuery::rejected(array('groups' => '8')));
    assertSame(false, ListQuery::rejected(array('status' => array('aktuell'), 'states' => array('BE'))));
    assertSame(false, ListQuery::rejected(array('groups' => array('8'))));
});

test('address group options keep the collmex names and drop an empty id', function () {
    $options = AddressGroup::options(array(
        array('id' => 8, 'name' => 'Gastronom | Laden | Einzelhandel'),
        array('id' => '0', 'name' => 'leer'),
        array('id' => 4, 'name' => 'Interessent'),
        array('id' => 139, 'name' => ''),
        array('id' => 9, 'name' => 'Händler'),
    ));
    assertSame(array(
        array('id' => '8', 'name' => 'Gastronom | Laden | Einzelhandel'),
        array('id' => '9', 'name' => 'Händler'),
        array('id' => '4', 'name' => 'Interessent'),
    ), $options);
});

test('address group filter matches imported ids and a full selection adds nothing', function () {
    $catalog = array('4', '8', '139');
    $open = InternalFilter::sql(array(
        'types' => array('laeden'),
    ), $catalog);
    assertNotContains('FIND_IN_SET', $open);

    $all = InternalFilter::sql(array(
        'types' => array('laeden'),
        'groups' => array('4', '8', '139', '0', "8' OR 1=1"),
    ), $catalog);
    assertNotContains('FIND_IN_SET', $all);
    assertNotContains('OR 1=1', $all);
    assertNotContains('1=0', $all);

    $one = InternalFilter::sql(array(
        'types' => array('laeden'),
        'groups' => array('139'),
    ), $catalog);
    assertContains("FIND_IN_SET('139', item.collmex_address_groups)", $one);
    assertNotContains("FIND_IN_SET('4'", $one);

    $none = InternalFilter::sql(array(
        'types' => array('laeden'),
        'groups' => array(''),
    ), $catalog);
    assertContains('1=0', $none);
    assertNotContains('FIND_IN_SET', $none);
});

finished();
