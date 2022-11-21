<?php

/**
 * Fund a new account on the test network with friendbot.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask for an address to fund.
$address = IO::prompt('Enter keyPair Address (or leave blank for random):');

// If no address was provided we will generate a new one.
if (empty($address)) {
    $keyPair = $bloom->keyPair->generate();
    $address = $keyPair->getAddress();

    IO::print("Randomly Generated keyPair:");
    IO::print("Address: {$keyPair->getAddress()}");
    IO::print("Seed:    {$keyPair->getSeed()}\n");
}

// Make the friendbot request
IO::print('Making Friendbot funding request...');
$response = $bloom->friendbot->fund($address);

if ($response instanceof HorizonError) {
    IO::error($response->getTitle() . ': ' . $response->getDetail());
    exit(1);
}

IO::success("Account {$address} has been funded.");
IO::success("https://stellar.expert/explorer/testnet/tx/{$response->getHash()}");

exit(0);
