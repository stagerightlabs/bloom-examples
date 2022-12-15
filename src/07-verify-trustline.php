<?php

/**
 * Verify that an account has a particular asset trustline.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask the user to provide an address
$address = IO::prompt('Please provide an address for the account to be checked:');
if (empty($address)) {
    IO::error('You must provide a secret key.');
    return exit(1);
}

// Ask for the asset that will given a trustline.
$identifier = IO::prompt('What asset are you looking for? ["Code:ISSUER"]:');

// Load the details of the source account from horizon
$keypair = $bloom->keypair->fromAddress($address);
$account = $bloom->account->retrieve($keypair);
if ($account instanceof HorizonError) {
    IO::error("The source account does not appear to be valid. ({$account->getTitle()})");
    exit(1);
}

if (empty($identifier)) {
    IO::info("Account {$account->getAddress()} has the following trustlines:");
    foreach ($account->getBalances() as $balance) {
        IO::print('- ' . $balance->getCanonicalAssetName());
    }
    exit(0);
}

if ($b = $account->getBalanceForAsset($identifier)) {
    IO::success("A trustline for {$identifier} was found");
} else {
    IO::error("A trustline for {$identifier} was not found");
}

exit(0);
