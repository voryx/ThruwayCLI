Thruway CLI
===========

Thruway CLI is a command line tool that allows you to execute WAMP commands for testing purposes.


## Installation

Download thruway-cli.phar from the latest [release](https://github.com/voryx/ThruwayCLI/releases/).

```bash
$ chmod 755 thruway-cli.phar
$ sudo mv thruway-cli.phar /usr/local/bin/thruway-cli
```

## Example

```bash
$ thruway-cli wss://demo.crossbar.io/ws realm1
```

## Commands

```bash
  exit [<code>] 
  help  
  publish <uri> <value> [<options>]     
  call <uri> [<args>] [<options>]       
  subscribe <uri> [<options>]       
  cancel        
  register <uri> [<options>]   
```
  
 
### Subscribe
```bash
thruway> subscribe demo.topic
thruway> subscribe demo. '{ "match": "prefix" }'
```    
  
### Publish
```bash
thruway> publish demo.topic 'Hello World'
thruway> publish demo.topic 'Hello World' '{"exclude_me":false}'

```  

### Call
```bash
thruway> call demo.rpc 123
thruway> call demo.rpc "Hello World"
thruway> call demo.rpc '["Hello", "World"]'
thruway> call demo.rpc '["Hello", "World"]' '{"receive_progress": true}'
```


### Register
```bash
thruway> register demo.rpc
thruway> register demo.rpc '{"progress": true}'
```
