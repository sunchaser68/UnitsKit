<?php

require __DIR__ . '/../vendor/autoload.php';
use deemru\UnitsKit;

if( 0 )
{
    $uk = UnitsKit::TESTNET();
    $uk->setPrivateKey( '0x33eb576d927573cff6ae50a9e09fc60b672a8dafdfbe3045c7f62955fc55ccb4' );
    $tx = $uk->tx( $uk->getAddress(), $uk->hexValue( 1.1 ), $uk->getGasPrice(), $uk->getNonce() );
    $tx = $uk->txEstimateGas( $tx );
    $tx = $uk->txSign( $tx );
    $tx = $uk->txBroadcast( $tx );
    $tx = $uk->ensure( $tx );
}

function a2b( $a )
{
    $b = '';
    foreach( $a as $c )
        $b .= chr( $c );

    return $b;
}

function ms( $ms )
{
    if( $ms > 100 )
        return round( $ms );
    else if( $ms > 10 )
        return sprintf( '%.01f', $ms );
    return sprintf( '%.02f', $ms );
}

class tester
{
    private $successful = 0;
    public $failed = 0;
    private $depth = 0;
    private $info = [];
    private $start = [];
    private $init;

    public function pretest( $info )
    {
        $this->info[$this->depth] = $info;
        $this->start[$this->depth] = microtime( true );
        if( !isset( $this->init ) )
            $this->init = $this->start[$this->depth];
        $this->depth++;
    }

    private function ms( $start )
    {
        $ms = ( microtime( true ) - $start ) * 1000;
        $ms = $ms > 100 ? round( $ms ) : $ms;
        $ms = sprintf( $ms > 10 ? ( $ms > 100 ? '%.00f' : '%.01f' ) : '%.02f', $ms );
        return $ms;
    }

    public function test( $cond )
    {
        global $uk;
        $this->depth--;
        $ms = $this->ms( $this->start[$this->depth] );
        $uk->log( $cond ? 's' : 'e', "{$this->info[$this->depth]} ($ms ms)" );
        $cond ? $this->successful++ : $this->failed++;
        return $cond;
    }

    public function finish()
    {
        $total = $this->successful + $this->failed;
        $ms = $this->ms( $this->init );
        echo "  TOTAL: {$this->successful}/$total ($ms ms)\n";
        sleep( 3 );

        if( $this->failed > 0 )
            exit( 1 );
    }
}

echo '   TEST: UnitsKit @ PHP ' . PHP_VERSION . PHP_EOL;
$t = new tester();

$uk = UnitsKit::MAINNET();

$t->pretest( 'MAINNET getChainId' );
{
    $chainId = $uk->getChainId();
    $chainId = hexdec( $chainId );
    $t->test( $chainId === $uk->chainId && $chainId === 88811 );
}

$uk = UnitsKit::TESTNET();

$t->pretest( 'TESTNET getChainId' );
{
    $chainId = $uk->getChainId();
    $chainId = hexdec( $chainId );
    $t->test( $chainId === $uk->chainId && $chainId === 88817 );
}

$t->pretest( 'getPublicKey' );
{
    $wellKnownPrivateKey = '0x33eb576d927573cff6ae50a9e09fc60b672a8dafdfbe3045c7f62955fc55ccb4';
    $uk->setPrivateKey( $wellKnownPrivateKey );
    $t->test( $uk->getPublicKey() == '0x20876c03fff2b09ea01861f3b3789ada54a20a8c5e90170618364cbb02d8e6408401e120158f489376a1db3f8cde24f9432976d2f89aeb193fb5becc094a28b9' );
}

$t->pretest( 'getAddress' );
{
    $t->test( $uk->getAddress() == '0x4e1c45599f667b4dc3604d69e43722d4ace6b770' );
}

$t->pretest( 'getBridgeProofs' );
{
    $etalon = '613a31303a7b693a303b733a33323a2203170a2e7597b7b7e3d84c05391d139a62b157e78786d8c082f29dcf4c111314223b693a313b733a33323a22bed1940ea357a65f6f274f1bb836ec32bc5ad66b049e33138320ea1bf7c5bbe2223b693a323b733a33323a227ef4bd72c40c944c4de470e179af26cb5b377706e336fc227429398dfacb96b8223b693a333b733a33323a2279e3dae159783789ba6f21ba942c8409639e4f1ae481c0e24f3de095fd09ac09223b693a343b733a33323a22fcbb3f09658c5441fdbbddad0d0cd4c8413a003e112dc6d9d7956359d7887bd8223b693a353b733a33323a22db51ffebd840b700a68862fd6ac558d1e13aa6122c3b93e0cda8127fa0f7cfef223b693a363b733a33323a2281dceeefee0af1fc3cbba3c6b27f07140aca334a03f5bf443d7e53a8a2144037223b693a373b733a33323a221e1b2f5821fefae163c6bd6659337c04be482d8550fdb456125909cc725149a7223b693a383b733a33323a22128892132a7cc9b87cb275eab13c00f7192e73c468ea110e950533e1b63954ed223b693a393b733a33323a22590e70d285db8df6fe8184d31f9be19bece1e29b835496463598c3429ae3dbfc223b7d';
    $tx = $uk->txByHash( '0xe7c1ea089c227e34736b7c23bc76bf3bf8a1e8d156731423d5534c571cc12546' );
    list( $proofs, $index ) = $uk->getBridgeProofs( $tx );
    $t->test( bin2hex( serialize( $proofs ) ) === $etalon && $index === 0 );
}

$t->pretest( 'txSign' );
{
    $tx = $uk->txSign( $tx );
    $t->test( isset( $tx['signed'] ) );
}

if( file_exists( __DIR__ . '/private.php' ) )
    require_once __DIR__ . '/private.php';

$privateKey = getenv( 'UNITSKIT_PRIVATEKEY' );
if( $privateKey !== false )
{
    $t->pretest( 'txBroadcast + ensure' );
    {
        $uk->setPrivateKey( $privateKey );
        $gasPrice = $uk->getGasPrice();
        $nonce = $uk->getNonce();
        $value = $uk->hexValue( 1.1 );
        for( $i = 0; $i < 8; ++$i ) // Known transaction? => increment `nonce`
        {
            $tx = $uk->tx( $uk->getAddress(), $value, $gasPrice, dechex( hexdec( $nonce ) + $i ) );
            $tx = $uk->txEstimateGas( $tx );
            $tx = $uk->txSign( $tx );
            $tx = $uk->txBroadcast( $tx );
            if( $tx === false )
                continue;
            $tx = $uk->ensure( $tx );
            if( $tx === false )
                continue;
            break;
        }

        $t->test( isset( $tx['succeed'] ) && $tx['succeed'] );
    }
}

$t->pretest( 'getBlockByNumber');
{	
    $t->test($uk->stringValue($uk->getBlockByNumber(489318)['number'],0) == '489318' );
}

$t->pretest( 'getBlockReceipts');
{			
    $t->test(count($uk->getBlockReceipts(489318)) >= 0 );
}

$t->finish();
