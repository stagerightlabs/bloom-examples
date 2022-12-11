<?php

$finder = PhpCsFixer\Finder::create()
    ->in('src')
    ->in('tests');

$config = new PhpCsFixer\Config();
return $config->setRules([
    '@PSR12' => true,
    'array_syntax' => ['syntax' => 'short'],
    'no_unused_imports' => true,
    'binary_operator_spaces' => ['operators' => ['=>' => 'align_single_space_minimal']],
    'ordered_imports' => ['sort_algorithm' => 'alpha'],
    'strict_param' => true,
])
    ->setFinder($finder);
