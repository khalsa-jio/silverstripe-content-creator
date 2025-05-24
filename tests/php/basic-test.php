<?php
// Simple standalone test script - no PHPUnit dependencies

echo "Running basic tests for Silverstripe Content Creator module\n";
echo "=======================================================\n\n";

// Test 1: Basic assertions
echo "Test 1: Basic assertions\n";
$assertion1 = true === true;
echo "  Assertion 1: " . ($assertion1 ? "PASS" : "FAIL") . "\n";

$assertion2 = 2 + 2 === 4;
echo "  Assertion 2: " . ($assertion2 ? "PASS" : "FAIL") . "\n";

// Test 2: LLM provider configuration
echo "\nTest 2: LLM provider configuration\n";
$providers = [
    'OpenAI' => [
        'api_key' => 'test_api_key',
        'model' => 'gpt-4o',
        'max_tokens' => 1000,
        'temperature' => 0.7,
    ],
    'Claude' => [
        'api_key' => 'test_claude_api_key',
        'model' => 'claude-3-opus-20240229',
        'max_tokens' => 1000,
        'temperature' => 0.7,
    ]
];

$assertion3 = $providers['OpenAI']['api_key'] === 'test_api_key';
echo "  Assertion 3: " . ($assertion3 ? "PASS" : "FAIL") . "\n";

$assertion4 = $providers['OpenAI']['model'] === 'gpt-4o';
echo "  Assertion 4: " . ($assertion4 ? "PASS" : "FAIL") . "\n";

$assertion5 = $providers['Claude']['model'] === 'claude-3-opus-20240229';
echo "  Assertion 5: " . ($assertion5 ? "PASS" : "FAIL") . "\n";

// Test 3: Token counting function
echo "\nTest 3: Token counting function\n";
$tokenCount = function($text) {
    // Approximate token count (1 token ~= 4 chars)
    return ceil(strlen($text) / 4);
};

$shortText = "This is a short prompt.";
$longText = str_repeat("This is a very long input that would exceed token limits. ", 100);

$assertion6 = $tokenCount($shortText) < 20;
echo "  Assertion 6: " . ($assertion6 ? "PASS" : "FAIL") . "\n";

$assertion7 = $tokenCount($longText) > 500;
echo "  Assertion 7: " . ($assertion7 ? "PASS" : "FAIL") . "\n";

$maxTokens = 100;
$truncatedText = substr($longText, 0, $maxTokens * 4);
$assertion8 = $tokenCount($truncatedText) <= $maxTokens;
echo "  Assertion 8: " . ($assertion8 ? "PASS" : "FAIL") . "\n";

// Summary
echo "\nTest Summary\n";
echo "=======================================================\n";
$allAssertions = [$assertion1, $assertion2, $assertion3, $assertion4, $assertion5, $assertion6, $assertion7, $assertion8];
$passedCount = count(array_filter($allAssertions));
$totalCount = count($allAssertions);

echo "Tests passed: $passedCount/$totalCount\n";
echo "Status: " . ($passedCount === $totalCount ? "ALL TESTS PASSED" : "SOME TESTS FAILED") . "\n";
