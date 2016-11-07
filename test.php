<?php

use Rx\Observable;
use Rx\React\FromFileObservable;
use Rx\Thruway\Client;

require __DIR__ . '/vendor/autoload.php';

$historyFile = ".history";
$client      = new Client($argv[1], $argv[2]);
$router      = new Clue\Commander\Router();
$disposable  = new \Rx\Disposable\SerialDisposable();
$loop        = \EventLoop\getLoop();

//$router->add('exit [<code>]', function (array $args) {
//    exit(isset($args['code']) ? (int)$args['code'] : 0);
//});
//
//$router->add('help', function () use ($router) {
//    echo 'Usage:' . PHP_EOL;
//    foreach ($router->getRoutes() as $route) {
//        echo '  ' . $route . PHP_EOL;
//    }
//});
//
//$router->add('publish [<uri>] [<value>]', function (array $args) use ($client) {
//    $client->publish($args['uri'], $args['value']);
//});
//
//$router->add('call [<uri>] [<value>...]', function (array $args) use ($client) {
//
//    //temp until Tokenizer bug fixed
//    if (!isset($args['uri']) || $args['value']) {
//        echo "Invalid Arguments for command 'call'", PHP_EOL;
//        return;
//    }
//
//    return $client->call($args['uri'], $args['value'])->pluck(0)->pluck(0);
//
//});
//
//$router->add('topic <uri>', function (array $args) use ($client) {
//    return $client->topic($args['uri'])->pluck(0)->pluck(0);
//});
//
//$router->add('cancel', function () use ($disposable) {
//    $disposable->dispose();
//});
//
//echo 'Thruway CLI: type help to see a list commands.' . PHP_EOL;
//
$readline = new Clue\React\Readline\Readline($loop, 'thruway> ');
//$readline->setAutocompleteWords(array_map(function ($route) {
//    return explode(' ', $route)[0];
//}, $router->getRoutes()));
//
$history = new React\Stream\Stream(fopen($historyFile, 'a'), $loop);
$readline->on('line', function ($line) use ($readline, $router, $disposable, $history) {

    if ($line === 'quit' || $line === 'exit') {
        $readline->pause();
        return;
    }

    if ($line === '') {
        return;
    }

    try {
        $readline->addHistory($line);
        $history->write($line . "\n");
        $args   = Clue\Arguments\split($line);
        $result = $router->handleArgs($args);

        if ($result instanceof Observable) {
            $subscription = $result->subscribeCallback(
                function ($r) {
                    echo json_encode($r);
                },
                function (Exception $e) {
                    echo $e->getMessage(), PHP_EOL;
                });
            $disposable->setDisposable($subscription);
        }

    } catch (Exception $e) {
        echo $e->getMessage(), PHP_EOL;
    }

});
//
////Reload history from disk
//(new FromFileObservable($historyFile))
//    ->cut()
//    ->filter(function ($line) {
//        //don't include blank lines
//        return $line !== '';
//    })
//    ->subscribeCallback(
//        function ($line) use ($readline) {
//            $readline->addHistory($line);
//        },
//        function (Exception $e) {
//            echo $e->getMessage();
//        }
//    );