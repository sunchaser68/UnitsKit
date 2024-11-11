<?php

namespace deemru;

use Elliptic\EC;

function jd( string $data )
{
    $json = json_decode( $data, true, 512, JSON_BIGINT_AS_STRING );
    return $json === null ? false : $json;
}

function h2b( string $hex )
{
    if( substr( $hex, 0, 2 ) === '0x' )
        $hex = substr( $hex, 2 );
    if( $hex === '' )
        return '';
    if( strlen( $hex ) % 2 !== 0 )
        $hex = '0' . $hex;
    if( !ctype_xdigit( $hex ) )
        return false;
    return hex2bin( $hex );
}

function b2h( string $bin )
{
    return '0x' . bin2hex( $bin );
}

class UnitsKit
{
    public $rpc;
    public $chainId;
    public $lastError;
    public $wk;
    private $privateKeyCloaked;
    private $publicKey;
    private $publicKeyHex;
    private $address;
    private $addressHex;

    static public function MAINNET()
    {
        return new UnitsKit
        (
            [
                'https://rpc.w8.io',
                'https://rpc.unit0.dev',
            ],
            88811,
        );
    }

    static public function TESTNET()
    {
        return new UnitsKit
        (
            [
                'https://rpc-testnet.w8.io',
                'https://rpc-testnet.unit0.dev',
            ],
            88817,
        );
    }

    public function __construct( $rpc, $chainId )
    {
        $this->rpc = Fetcher::hosts( is_array( $rpc ) ? $rpc : [ $rpc ] );
        $this->chainId = $chainId;
        $this->wk = new WavesKit;
    }

    public function log( $level, $message = null )
    {
        return $this->wk->log( $level, $message );
    }

    public function hexValue( $value, $decimals = 18 )
    {
        $value = strval( $value );
        if( strpos( $value, '.' ) !== false )
        {
            [ $integer, $fractional ] = explode( '.', $value );
            $fractional = substr( str_pad( $fractional, 18, '0' ), 0, 18 );
        }
        else
        {
            $integer = $value;
            $fractional = 0;
        }

        $decimals = '1' . str_repeat( '0', $decimals );
        $gmp = gmp_add( gmp_mul( $integer, $decimals ), $fractional );
        return '0x' . gmp_strval( $gmp, 16 );
    }

    public function stringValue( $value, $decimals = 18 )
    {
        $value = gmp_init( $value, 16 );

        $sign = '';
        if( gmp_sign( $value ) === -1 )
        {
            $sign = '-';
            $value = -$value;
        }
        $value = (string)$value;
        if( $decimals )
        {
            if( strlen( $value ) <= $decimals )
                $value = str_pad( $value, $decimals + 1, '0', STR_PAD_LEFT );
            $value = substr_replace( $value, '.', -$decimals, 0 );
        }

        return $sign . $value;
    }

    private function cleanup()
    {
        unset( $this->privateKeyCloaked );
        unset( $this->publicKey );
        unset( $this->publicKeyHex );
        unset( $this->address );
        unset( $this->addressHex );
    }

    private function fetcher( $method, $params = '', $verbose = true )
    {
        $json = $this->rpc->fetch( '/', true, '{"jsonrpc":"2.0","method":"' . $method . '","params":[' . $params . '],"id":1}' );
        if( $json === false || false === ( $json = jd( $json ) ) || isset( $json['error'] ) )
        {
            $error = "fetcher() unknown error";
            if( isset( $json['error']['message'] ) )
                $error = $json['error']['message'];
            if( $verbose  )
                $this->log( 'e', $error );
            $this->lastError = $error;
            return false;
        }

        return $json['result'];
    }

    public function height()
    {
        return hexdec( $this->fetcher( 'eth_blockNumber' ) );
    }

    public function getTransactionReceipt( $hash )
    {
        $receipt = $this->fetcher( 'eth_getTransactionReceipt', '"' . $hash . '"' );
        if( $receipt === false || $receipt === null )
            return false;
        return $receipt;
    }

    public function getTransactionByHash( $hash )
    {
        return $this->fetcher( 'eth_getTransactionByHash', '"' . $hash . '"' );
    }

    public function txByHash( $hash )
    {
        $tx = $this->getTransactionByHash( $hash );
        if( $tx === false )
            return false;
        $receipt = $this->getTransactionReceipt( $hash );
        if( $receipt !== false )
        {
            $tx['receipt'] = $receipt;
            $tx['succeed'] = isset( $receipt['status'] ) && $receipt['status'] === '0x1';
        }
        return $tx;
    }

    public function getBridgeLogs( $blockHash, $address = '0x0000000000000000000000000000000000006a7e', $topics = [ '0xfeadaf04de8d7c2594453835b9a93b747e20e7a09a7fdb9280579a6dbaf131a8' ] )
    {
        return $this->fetcher( 'eth_getLogs', json_encode(
        [
            'blockHash' => $blockHash,
            'address' => $address,
            'topics' => $topics,
        ] ) );
    }

    public function getBridgeTree( $blockHash )
    {
        $logs = $this->getBridgeLogs( $blockHash );
        if( $logs === false )
            return false;

        $tree = [];
        foreach( $logs as $log )
        {
            $index = hexdec( $log['logIndex'] );
            $tree[$index] = h2b( $log['data'] );
        }

        return $tree;
    }

    private static $merkleBridgeDefault;
    private static $merkleBridgeKnown;

    private function merkleBridgeInit()
    {
        if( isset( self::$merkleBridgeDefault ) )
            return;

        self::$merkleBridgeDefault = $this->blake2b256( chr( 0 ) );
        $hash = self::$merkleBridgeDefault;
        for( $i = 0; $i < 9; ++$i )
        {
            $value = $hash . $hash;
            $hash = $this->blake2b256( $value );
            self::$merkleBridgeKnown[$value] = $hash;
        }
    }

    private function merkleBridgeProofs( $tree, $index, $size = 1024 )
    {
        $this->merkleBridgeInit();

        $path = [];
        $q = $size >> 1;
        $p = $q;
        for( ;; )
        {
            if( $index < $p )
            {
                $path[$q] = true;
                $q >>= 1;
                if( $q === 0 )
                    break;
                $p -= $q;
            }
            else
            {
                $path[$q] = false;
                $q >>= 1;
                if( $q === 0 )
                    break;
                $p -= $q;
            }
        }

        $proofs = [];
        $p = 0;
        foreach( $path as $q => $lr )
        {
            $proofs[] = $this->merkleBridgeHashAt( $tree, $p + $lr, $q );
            if( $q !== 1 )
            {
                $p += $lr ? 0 : 1;
                $p <<= 1;
            }
        }

        return array_reverse( $proofs );
    }

    private function merkleBridgeHashAt( $tree, $p, $q )
    {
        if( $q === 1 )
        {
            if( !isset( $tree[$p] ) )
                return self::$merkleBridgeDefault;

            return $this->blake2b256( $tree[$p] );
        }
        else
        {
            $p <<= 1;
            $q >>= 1;
            $l = $this->merkleBridgeHashAt( $tree, $p + 0, $q );
            $r = $this->merkleBridgeHashAt( $tree, $p + 1, $q );
            $value = $l . $r;
            if( isset( self::$merkleBridgeKnown[$value] ) )
                return self::$merkleBridgeKnown[$value];
            return $this->blake2b256( $value );
        }
    }

    public function getBridgeProofs( $tx )
    {
        if( !isset( $tx['receipt'] ) || !isset( $tx['succeed'] ) || !$tx['succeed'] )
        {
            $this->log( 'e', 'txBridgeProofs() not succeed tx' );
            return false;
        }

        if( !isset( $tx['receipt']['logs'][0]['logIndex'] ) || !isset( $tx['receipt']['logs'][0]['data'] ) )
        {
            $this->log( 'e', 'txBridgeProofs() not bridged tx' );
            return false;
        }

        $tree = $this->getBridgeTree( $tx['receipt']['blockHash'] );
        if( $tree === false )
            return false;

        $logIndex = hexdec( $tx['receipt']['logs'][0]['logIndex'] );
        if( !isset( $tree[$logIndex] ) )
        {
            $this->log( 'e', 'txBridgeProofs() not found logIndex' );
            return false;
        }

        $logData = h2b( $tx['receipt']['logs'][0]['data'] );
        if( $tree[$logIndex] !== $logData )
        {
            $this->log( 'e', 'txBridgeProofs() not found logData' );
            return false;
        }

        return $this->merkleBridgeProofs( $tree, $logIndex, 1024 );
    }

    public function txBroadcast( $tx, $verbose = true )
    {
        if( !isset( $tx['signed'] ) || !isset( $tx['hash'] ) )
        {
            $this->log( 'e', 'txBroadcast() not signed tx' );
            return false;
        }

        $result = $this->fetcher( 'eth_sendRawTransaction', '"' . $tx['signed'] . '"', $verbose );
        if( $result === false )
            return false;

        if( $result !== $tx['hash'] )
        {
            $this->log( 'e', 'txBroadcast() result (' . $result . ') differs from tx hash (' . $tx['hash'] . ')' );
            return false;
        }

        $tx['broadcasted'] = true;
        return $tx;
    }

    /**
     * Ensures a transaction confirmed and reached required confirmations
     *
     * @param  array     $tx             Transaction as an array
     * @param  int       $confirmations  Number of confirmations to reach (default: 0)
     * @param  int|float $sleep          Seconds to sleep between requests (default: 1.0)
     * @param  int       $timeout        Timeout to reach lost status (default: 30)
     * @param  bool      $hard           Use hard timeout (default: false)
     *
     * @return array|false Ensured transaction as an array or FALSE on failure
     */
    public function ensure( $tx, $confirmations = 0, $sleep = 1.0, $timeout = 30, $hard = false )
    {
        if( !isset( $tx['broadcasted'] ) || !$tx['broadcasted'] )
        {
            $this->log( 'e', 'ensure() not broadcasted tx' );
            return false;
        }

        $id = $tx['hash'];
        $n = 0;
        $n_utx = 0;
        $usleep = (int)( $sleep * 1000000 );
        $tsleep = 0;

        while( false === ( $receipt = $this->getTransactionReceipt( $id ) ) )
        {
            if( $usleep === 0 )
                return false;

            if( $hard && $n > $timeout )
            {
                $this->log( 'w', "($id) hard timeout reached ($n)" );
                return false;
            }

            usleep( $usleep );
            $tsleep += $usleep;
            if( (int)( ( 1 + $tsleep ) / 1000000 ) === $n )
                continue;

            ++$n;
            $n_diff = $n - $n_utx;
            if( $n_utx )
            {
                $n_diff = $n - $n_utx;
                if( $n_diff > $timeout )
                {
                    if( false === $this->txBroadcast( $tx, false ) &&
                        false === strpos( $this->lastError, 'Known' ) )
                    {
                        $this->log( 'e', "($id) rebroadcast failed (timeout reached)" );
                        return false;
                    }

                    $this->log( 'w', "($id) rebroadcasted ($n)" );
                    $n_utx = 0;
                    continue;
                }

                if( $n_diff >= 1 )
                    $this->log( 'i', "($id) still unconfirmed ($n) (timeout $n_diff/$timeout)" );
            }
            else
            {
                if( $n_diff > $timeout )
                {
                    if( false === $this->txBroadcast( $tx, false ) &&
                        false === strpos( $this->lastError, 'Known' ) )
                    {
                        $n_utx = $n;
                        continue;
                    }
                }

                $this->log( 'i', "($id) unconfirmed ($n)" );
            }
        }

        $succeed = isset( $receipt['status'] ) && $receipt['status'] === '0x1';
        if( $usleep !== 0 )
        {
            if( $succeed )
                $this->log( 's', "($id) confirmed" . ( $n > 0 ? " ($n)" : '' ) );
            else
                $this->log( 'e', "($id) failed" . ( $n > 0 ? " ($n)" : '' ) );
        }

        if( $succeed && $confirmations > 0 )
        {
            $n = 0;
            $txHeight = hexdec( $receipt['blockNumber'] );
            while( $confirmations > ( $c = $this->height() - $txHeight ) )
            {
                if( $usleep === 0 )
                    return false;

                $n++;
                $this->log( 'i', "($id) $c/$confirmations confirmations ($n)" );
                sleep( $sleep > 1 ? (int)$sleep : $confirmations );
            }

            if( $receipt !== $this->getTransactionReceipt( $id ) )
            {
                $this->log( 'w', "($id) change detected" );
                $this->rpc->resetCache();
                return $this->ensure( $tx, $confirmations, $sleep, $timeout, $hard );
            }

            $this->log( 's', "($id) reached $c confirmations" );
            $tx['confirmations'] = $c;
        }

        $tx['receipt'] = $receipt;
        $tx['succeed'] = $succeed;
        return $tx;
    }

    public function getBalance( $address = null )
    {
        if( !isset( $address ) )
            $address = $this->getAddress();
        return $this->fetcher( 'eth_getBalance', '"' . $address . '", "latest"' );
    }

    public function getGasPrice()
    {
        return $this->fetcher( 'eth_gasPrice' );
    }

    public function getChainId()
    {
        return $this->fetcher( 'eth_chainId' );
    }

    public function getEstimateGas( $tx )
    {
        return $this->fetcher( 'eth_estimateGas', json_encode( $tx ) );
    }

    public function txEstimateGas( $tx )
    {
        $gas = $this->getEstimateGas( $tx );
        if( $gas === false )
            return false;
        $tx['gas'] = $gas;
        return $tx;
    }

    public function getTransactionCount( $address = null )
    {
        if( !isset( $address ) )
            $address = $this->getAddress();
        return $this->fetcher( 'eth_getTransactionCount', '"' . $address . '","latest"' );
    }

    public function getNonce()
    {
        return $this->getTransactionCount( $this->getAddress() );
    }

    public function setPrivateKey( $key )
    {
        $this->cleanup();
        $key = strlen( $key ) === 32 ? $key : h2b( $key );
        if( strlen( $key ) !== 32 )
            return false;
        $this->privateKeyCloaked = new Cloaked;
        $this->privateKeyCloaked->cloak( $key );
    }

    public function getPublicKey( $hex = true )
    {
        if( !isset( $this->publicKey ) )
        {
            $this->publicKey = $this->privateKeyCloaked->uncloak( function( $key )
            {
                $temp = $key;
                if( $temp === false || strlen( $temp ) !== 32 )
                    return false;
                $key = ( new EC( 'secp256k1' ) )->keyFromPrivate( bin2hex( $key ), 'hex' );
                $key = $key->getPublic();
                return h2b( str_pad( $key->x->toString( 'hex' ), 64, '0' ) . str_pad( $key->y->toString( 'hex' ), 64, '0' ) );
            } );
            $this->publicKeyHex = b2h( $this->publicKey );
        }

        return $hex ? $this->publicKeyHex : $this->publicKey;
    }

    public function getAddress( $hex = true )
    {
        if( !isset( $this->address ) )
        {
            $hash = $this->wk->keccak256( $this->getPublicKey( false ) );
            $this->address = substr( $hash, -20 );
            $this->addressHex = b2h( $this->address );
        }

        return $hex ? $this->addressHex : $this->address;
    }

    public function keccak256( $data )
    {
        return $this->wk->keccak256( $data );
    }

    public function blake2b256( $data )
    {
        return $this->wk->blake2b256( $data );
    }

    public function encodeRLP( $input )
    {
        if( is_string( $input ) )
        {
            $len = strlen( $input );
            if( $len === 1 && ord( $input ) < 0x80 )
                return $input;
            return $this->encodeRLPLength( $len, 0x80 ) . $input;
        }
        if( is_array( $input ) )
        {
            $encoded = '';
            foreach( $input as $item )
            {
                $item = $this->encodeRLP( $item );
                if( $item === false )
                    return false;
                $encoded .= $item;
            }
            return $this->encodeRLPLength( strlen( $encoded ), 0xc0 ) . $encoded;
        }
        return false;
    }

    public function encodeRLPLength( $len, $offset )
    {
        if( $len < 56 )
            return chr( $len + $offset );
        $binLen = hex2bin( dechex( $len ) );
        return chr( strlen( $binLen ) + $offset + 55 ) . $binLen;
    }

    public function tx( $to, $value, $gas, $gasPrice, $input, $nonce )
    {
        return
        [
            'from' => $this->getAddress(),
            'nonce' => $nonce,
            'gasPrice' => $gasPrice,
            'gas' => $gas,
            'to' => $to,
            'value' => $value,
            'input' => $input,
        ];
    }

    private function txInput( $tx, $full )
    {
        $nonce = hexdec( $tx['nonce'] ) === 0 ? '' : $tx['nonce'];
        $value = hexdec( $tx['value'] ) === 0 ? '' : $tx['value'];

        return
        $full ?
        [
            h2b( $nonce ),
            h2b( $tx['gasPrice'] ),
            h2b( $tx['gas'] ),
            h2b( $tx['to'] ),
            h2b( $value ),
            h2b( $tx['input'] ),
            h2b( $tx['v'] ),
            h2b( $tx['r'] ),
            h2b( $tx['s'] ),
        ]
        :
        [
            h2b( $nonce ),
            h2b( $tx['gasPrice'] ),
            h2b( $tx['gas'] ),
            h2b( $tx['to'] ),
            h2b( $value ),
            h2b( $tx['input'] ),
        ];
    }

    function txHash( $signed )
    {
        return b2h( $this->keccak256( h2b( $signed ) ) );
    }

    function txSign( $tx, $chainId = null )
    {
        if( !isset( $chainId ) )
            $chainId = $this->chainId;

        if( $chainId > 0 )
        {
            $tx['v'] = dechex( $chainId );
            $tx['r'] = '';
            $tx['s'] = '';
            $encoded = $this->encodeRLP( $this->txInput( $tx, true ) );
        }
        else
        {
            $encoded = $this->encodeRLP( $this->txInput( $tx, false ) );
        }

        if( $encoded === false )
            return false;

        $hash = $this->keccak256( $encoded );
        $signature = $this->privateKeyCloaked->uncloak( function( $key ) use ( $hash )
        {
            $temp = $key;
            if( $temp === false || strlen( $temp ) !== 32 )
                return false;
            $key = ( new EC( 'secp256k1' ) )->keyFromPrivate( bin2hex( $key ), 'hex' );
            return $key->sign( bin2hex( $hash ), 'hex', [ 'canonical' => true ] );
        } );

        $tx['v'] = dechex( $signature->recoveryParam + 27 + ( $chainId > 0 ? ( $chainId * 2 + 8 ) : 0 ) );
        $tx['r'] = str_pad( $signature->r->toString( 'hex' ), 64, '0' );
        $tx['s'] = str_pad( $signature->s->toString( 'hex' ), 64, '0' );
        $encoded = $this->encodeRLP( $this->txInput( $tx, true ) );
        if( $encoded === false )
            return false;
        $tx['hash'] = b2h( $this->keccak256( $encoded ) );
        $tx['signed'] = b2h( $encoded );
        return $tx;
    }
}
