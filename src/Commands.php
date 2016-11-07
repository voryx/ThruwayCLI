<?php

namespace Voryx;

use Clue\Commander\Router;
use Rx\Disposable\SerialDisposable;
use Rx\Thruway\Client;
use Rx\Extra\Observable\FromEventEmitterObservable;
use Rx\Scheduler\EventLoopScheduler;
use Clue\React\Readline\Readline;
use Rx\React\FromFileObservable;
use Rx\Observable;

class Commands
{
    private $wamp, $router, $awaitingReply, $disposable, $historyFile, $line;

    public function __construct(Client $wamp, Router $router)
    {
        $this->wamp          = $wamp;
        $this->router        = $router;
        $this->awaitingReply = false;
        $this->disposable    = new SerialDisposable();
        $this->historyFile   = $_SERVER['HOME'] . "/.thruway-history";

        $loop = \EventLoop\getLoop();

        $readLine = new readLine($loop, 'thruway> ');
        $readLine->setAutocompleteWords(array_map(function ($route) {
            return explode(' ', $route)[0];
        }, $router->getRoutes()));

        //Read the input from the CLI
        $input = (new FromEventEmitterObservable($readLine, 'line'))
            ->pluck(0)
            ->filter(function ($line) {
                return trim($line) !== '';
            })
            ->share();

        //Split off the quit stream
        list($quit, $this->line) = $input->partition(function ($l) {
            return ($l === 'quit' || $l === 'exit');
        });

        //Process the line through the cli router
        $this->line
            ->filter(function () {
                return $this->awaitingReply === false;
            })
            ->map('Clue\Arguments\split')
            ->map([$router, 'handleArgs'])
            ->catchError(function (\Exception $e) {
                echo $e->getMessage(), PHP_EOL;
                throw $e;
            })
            ->retry()
            ->filter(function ($result) {
                return $result instanceof Observable;
            })
            ->subscribeCallback(function (Observable $result) {
                $subscription = $result->subscribeCallback(
                    function ($r) {
                        echo json_encode($r), PHP_EOL;
                    },
                    function (\Exception $e) {
                        echo $e->getMessage(), PHP_EOL;
                    });
                $this->disposable->setDisposable($subscription);
            });

        //Record History
        $history = fopen($this->historyFile, 'a');
        $input->subscribeCallback(function ($line) use ($history) {
            fwrite($history, $line . PHP_EOL);
        });

        //Reload history from disk
        (new FromFileObservable($this->historyFile))
            ->cut()
            ->filter(function ($line) {
                //don't include blank lines
                return $line !== '';
            })
            ->distinctUntilChanged()
            ->subscribeCallback(
                [$readLine, 'addHistory'],
                function (\Exception $e) {
                    echo $e->getMessage();
                }
            );

        //Handle Quit
        $quit
            ->delay(100, new EventLoopScheduler($loop))
            ->subscribeCallback(function ($line) use ($readLine, $loop) {
                $readLine->pause();
                $loop->stop();
            });
    }

    public function call(array $args):Observable
    {
        return $this->wamp->call($args['uri'], (array)json_decode($args['args'] ?? null), [], (array)json_decode($args['options'] ?? null));
    }

    public function register(array $args):Observable
    {
        return $this->wamp->registerExtended($args['uri'], function ($args = null, $argskw = null, $details) {
            $this->awaitingReply = true;
            echo "RPC called with: ", json_encode($args), PHP_EOL;

            if (isset($details->receive_progress) && $details->receive_progress === true) {
                echo "Reply to RPC. Press enter to send and type 'done' when finished.", PHP_EOL, "thruway> ";
                return $this->line->takeWhile(function ($l) {
                    return $l !== "done";
                })->doOnCompleted(function () {
                    $this->awaitingReply = false;
                });
            }

            echo "Reply to RPC", PHP_EOL, "thruway> ";
            return $this->line->take(1)->doOnNext(function () {
                $this->awaitingReply = false;
            });

        }, (array)json_decode($args['options'] ?? null));
    }

    public function publish(array $args)
    {
        $this->wamp->publish($args['uri'], $args['value'], (array)json_decode($args['options'] ?? null));
    }

    public function subscribe(array $args):Observable
    {
        return $this->wamp->topic($args['uri'], (array)json_decode($args['options'] ?? null));
    }

    public function exit(array $args)
    {
        exit(isset($args['code']) ? (int)$args['code'] : 0);
    }

    public function help()
    {
        echo 'Usage:' . PHP_EOL;
        foreach ($this->router->getRoutes() as $route) {
            echo '  ' . $route . PHP_EOL;
        }
    }

    public function cancel()
    {
        $this->disposable->dispose();
    }
}
