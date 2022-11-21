<?php

/**
 * Generate a new random key pair.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;

$bloom = Bloom::make();
$keypair = $bloom->keypair->generate();

IO::success("Randomly Generated Keypair:");
IO::print(IO::color("Address:", IO::COLOR_GREEN) . " {$keypair->getAddress()}");
IO::print(IO::color("Seed:", IO::COLOR_GREEN) . "    {$keypair->getSeed()}");

exit(0);
