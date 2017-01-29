<?php

namespace Voryx;

use Rx\Observable;
use Rx\Scheduler;
use Rx\Thruway\Client;
use Interop\Async\Loop;
use Clue\Commander\Router;
use Clue\React\Stdio\Stdio;
use Clue\React\Stdio\Readline;
use Rx\React\FromFileObservable;
use Rx\Disposable\SerialDisposable;
use Rx\Observable\FromEventEmitterObservable;
use WyriHaximus\React\AsyncInteropLoop\AsyncInteropLoop;

class Commands
{
    private $wamp, $router, $awaitingReply, $disposable, $historyFile;

    private $defaultPrompt = 'thruway> ';

    private $stdio;

    /** @var  Readline */
    private $readLine;

    /** @var  Observable */
    private $line;

    public function __construct(Client $wamp, Router $router)
    {
        $this->wamp          = $wamp;
        $this->router        = $router;
        $this->awaitingReply = false;
        $this->disposable    = new SerialDisposable();
        $this->historyFile   = $_SERVER['HOME'] . '/.thruway-history';
        $this->stdio         = new Stdio(new AsyncInteropLoop());
        $this->readLine      = $this->stdio->getReadline();
        $this->readLine->setPrompt($this->defaultPrompt);

        $this->readLine->setAutocomplete(function ($word, $offset) use ($router) {

            if ($offset <= 1) {
                return array_map(function ($route) {
                    return explode(' ', $route)[0];
                }, $router->getRoutes());
            }

            $result = [];
            Observable::fromArray($this->readLine->listHistory(), Scheduler::getImmediate())
                ->filter(function ($item) {
                    return 0 === strpos($item, $this->readLine->getInput());
                })
                ->map(function ($item) use ($word) {
                    return $word . ltrim($item, $this->readLine->getInput());
                })
                ->toArray()
                ->subscribe(function ($a) use (&$result) {
                    $result = $a;
                });

            return $result;
        });

        //Read the input from the CLI
        $input = (new FromEventEmitterObservable($this->readLine))
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
            ->catch(function (\Exception $e) {
                $this->stdio->write($e->getMessage() . PHP_EOL);
                throw $e;
            })
            ->retry()
            ->filter(function ($result) {
                return $result instanceof Observable;
            })
            ->subscribe(function (Observable $result) {
                $subscription = $result->subscribe(
                    function ($r) {
                        $this->stdio->write(json_encode($r) . PHP_EOL);
                    },
                    function (\Exception $e) {
                        $this->stdio->write($e->getMessage() . PHP_EOL);
                    });
                $this->disposable->setDisposable($subscription);
            });

        //Record History
        $history = fopen($this->historyFile, 'ab');
        $input->subscribe(function ($line) use ($history) {
            $all = $this->readLine->listHistory();

            // skip empty line and duplicate of previous line
            if (trim($line) !== '' && $line !== end($all)) {
                $this->readLine->addHistory($line);
            }

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
            ->subscribe(
                [$this->readLine, 'addHistory'],
                function (\Exception $e) {
                    $this->stdio->write($e->getMessage());
                }
            );

        //Handle Quit
        $quit
            ->delay(100)
            ->subscribe(function ($line) {
                $this->readLine->pause();
                Loop::stop();
            });
    }

    public function call(array $args): Observable
    {
        return $this->wamp->call($args['uri'], (array)json_decode($args['args'] ?? null), [], (array)json_decode($args['options'] ?? null));
    }

    public function register(array $args): Observable
    {
        $uri = $args['uri'];
        return $this->wamp->registerExtended($uri, function ($args = null, $argskw = null, $details) use ($uri) {
            $this->awaitingReply = true;
            $this->stdio->write("RPC '{$uri}' called with: " . json_encode($args) . PHP_EOL);

            if (isset($details->receive_progress) && $details->receive_progress === true) {

                $this->stdio->write("Type 'done' when finished." . PHP_EOL);
                $this->readLine->setPrompt('Reply to RPC with valid json: ');
                return $this->line
                    ->takeWhile(function ($l) {
                        return $l !== 'done';
                    })
                    ->doOnCompleted(function () {
                        $this->awaitingReply = false;
                        $this->readLine->setPrompt($this->defaultPrompt);
                    });
            }

            $this->readLine->setPrompt('Reply to RPC with valid json: ');

            return $this->line
                ->take(1)
                ->map('json_decode')
                ->do(function () {
                    $this->awaitingReply = false;
                    $this->readLine->setPrompt($this->defaultPrompt);
                });

        }, (array)json_decode($args['options'] ?? null));
    }

    public function publish(array $args)
    {
        $this->wamp->publish($args['uri'], $args['value'], (array)json_decode($args['options'] ?? null));
    }

    public function subscribe(array $args): Observable
    {
        return $this->wamp->topic($args['uri'], (array)json_decode($args['options'] ?? null));
    }

    public function exit(array $args)
    {
        exit(isset($args['code']) ? (int)$args['code'] : 0);
    }

    public function help()
    {
        $this->stdio->write('Usage:' . PHP_EOL);
        foreach ($this->router->getRoutes() as $route) {
            $this->stdio->write('  ' . $route . PHP_EOL);
        }
    }

    public function cancel()
    {
        $this->disposable->dispose();
    }
}
