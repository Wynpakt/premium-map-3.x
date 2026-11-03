<?php

/**
 * One ADRGRP row: Collmex group number and its name.
 * https://www.collmex.de/handbuch_pro.html — Satzbeschreibung Adressgruppen
 */
class CollmexAddressGroup
{
    public static function fromRow(array $row)
    {
        if (!isset($row[0]) || $row[0] !== 'ADRGRP') {
            return null;
        }
        $id = isset($row[1]) ? (int) $row[1] : 0;
        $name = isset($row[2]) ? trim((string) $row[2]) : '';
        if ($id <= 0 || $name === '') {
            return null;
        }

        return array(
            'id' => $id,
            'name' => $name,
        );
    }
}
