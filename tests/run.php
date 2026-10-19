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

test('collmex row keeps phone at index 15 and the fields the sync already used', function () {
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

test('feedback router matches country and zip prefix', function () {
    $contacts = FeedbackRouter::parseLines("DE;10;max@example.test\nAT;1;doerte@example.test\n");
    assertSame('max@example.test', FeedbackRouter::route($contacts, 'DE', '10115'));
    assertSame('doerte@example.test', FeedbackRouter::route($contacts, 'at', '1010'));
    assertSame(FeedbackRouter::DEFAULT_EMAIL, FeedbackRouter::route($contacts, 'CH', '8000'));
});

test('smtp from config becomes one address', function () {
    $parsed = MailAddress::parse(array('landkarte@premium-cola.de' => 'Premium Landkarte'));
    assertSame('landkarte@premium-cola.de', $parsed['email']);
    assertSame('Premium Landkarte', $parsed['name']);
    assertSame('ada@example.test', MailAddress::parse('ada@example.test')['email']);
    assertSame(null, MailAddress::parse(''));
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

test('street compare folds straße and ignores punctuation', function () {
    assertSame(false, StreetCompare::differs('Torstraße 1', 'Torstr. 1'));
    assertSame(true, StreetCompare::differs('Torstraße 1', 'Linienstraße 1'));
    assertSame(false, StreetCompare::differs('Torstraße 1', ''));
});

finished();
