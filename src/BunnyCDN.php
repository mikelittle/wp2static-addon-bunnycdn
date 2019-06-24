<?php

namespace WP2Static;

use Exception;

class BunnyCDN extends SitePublisher {

    public function __construct() {
        $plugin = Controller::getInstance();

        $this->api_base = 'https://storage.bunnycdn.com';
        $this->batch_size =
            $plugin->options->getOption( 'deployBatchSize' );
        $this->storage_zone_name =
            $plugin->options->getOption( 'bunnycdnStorageZoneName' );
        $this->storage_zone_access_key =
            $plugin->options->getOption( 'bunnycdnStorageZoneAccessKey' );
        $this->pull_zone_access_key =
            $plugin->options->getOption( 'bunnycdnPullZoneAccessKey' );
        $this->pull_zone_id =
            $plugin->options->getOption( 'bunnycdnPullZoneID' );
        $this->cdn_remote_path =
            $plugin->options->getOption( 'bunnycdnRemotePath' );
    }

    public function bunnycdn_transfer_files() {
        $this->client = new Request();

        $lines = $this->getItemsToDeploy( $this->batch_size );

        foreach ( $lines as $local_file => $target_path ) {
            $abs_local_file = SiteInfo::getPath( 'uploads' ) .
                'wp2static-exported-site/' .
                $local_file;

            if ( ! is_file( $abs_local_file ) ) {
                $err = 'COULDN\'T FIND LOCAL FILE TO DEPLOY: ' .
                    $this->local_file;
                WsLog::l( $err );
                throw new Exception( $err );
            }

            if ( isset( $this->cdn_remote_path ) ) {
                $target_path =
                    $this->cdn_remote_path . '/' .
                        $target_path;
            }

            if ( ! DeployCache::fileIsCached( $abs_local_file ) ) {
                $this->createFileInBunnyCDN( $abs_local_file, $target_path );

                DeployCache::addFile( $abs_local_file );
            }

            DeployQueue::remove( $local_file );
        }

        $this->pauseBetweenAPICalls();

        if ( $this->uploadsCompleted() ) {
            $this->finalizeDeployment();
        }
    }

    public function bunnycdn_purge_cache() {
        try {
            $endpoint = 'https://bunnycdn.com/api/pullzone/' .
                $this->pull_zone_id . '/purgeCache';

            $ch = curl_init();

            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
            curl_setopt( $ch, CURLOPT_URL, $endpoint );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
            curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 0 );
            curl_setopt( $ch, CURLOPT_POST, 1 );

            curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                array(
                    'Content-Type: application/json',
                    'Content-Length: 0',
                    'AccessKey: ' .
                        $this->pull_zone_access_key,
                )
            );

            $output = curl_exec( $ch );
            $status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

            curl_close( $ch );

            $good_response_codes = array( '100', '200', '201', '302' );

            if ( ! in_array( $status_code, $good_response_codes ) ) {
                $err =
                    'BAD RESPONSE DURING BUNNYCDN PURGE CACHE: ' . $status_code;
                WsLog::l( $err );
                throw new Exception( $err );

                echo 'FAIL';
            }

            if ( ! defined( 'WP_CLI' ) ) {
                echo 'SUCCESS';
            }
        } catch ( Exception $e ) {
            WsLog::l( 'BUNNYCDN EXPORT: error encountered' );
            WsLog::l( $e );
        }
    }

    public function test_bunnycdn() {
        try {
            $remote_path = $this->api_base . '/' .
                $this->storage_zone_name .
                '/tmpFile';

            $ch = curl_init();

            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PUT' );
            curl_setopt( $ch, CURLOPT_URL, $remote_path );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
            curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
            curl_setopt( $ch, CURLOPT_HEADER, 0 );
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 );
            curl_setopt( $ch, CURLOPT_POST, 1 );

            curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                array(
                    'AccessKey: ' .
                        $this->storage_zone_access_key,
                )
            );

            $post_options = array(
                'body' => 'Test WP2Static connectivity',
            );

            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                $post_options
            );

            $output = curl_exec( $ch );
            $status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

            curl_close( $ch );

            $good_response_codes =
                array( '100', '200', '201', '301', '302', '304' );

            if ( ! in_array( $status_code, $good_response_codes ) ) {
                $err =
                    'BAD RESPONSE DURING BUNNYCDN TEST DEPLOY: ' . $status_code;
                WsLog::l( $err );
                throw new Exception( $err );
            }
        } catch ( Exception $e ) {
            WsLog::l( 'BUNNYCDN EXPORT: error encountered' );
            WsLog::l( $e );
        }

        if ( ! defined( 'WP_CLI' ) ) {
            echo 'SUCCESS';
        }
    }

    public function createFileInBunnyCDN(
        string $local_file,
        string $target_path
    ) : void {
        $remote_path = $this->api_base . '/' .
            $this->storage_zone_name .
            '/' . $target_path;

        $headers = array( 'AccessKey: ' . $this->storage_zone_access_key );

        $this->client->putWithFileStreamAndHeaders(
            $remote_path,
            $local_file,
            $headers
        );

        $success = $this->checkForValidResponses(
            $this->client->status_code,
            array( '100', '200', '201', '301', '302', '304' )
        );

        if ( ! $success ) {
            $err = "Received {$this->client->status_code} response from API " .
               "with body: {$this->client->body}";
            WsLog::l( $err );

            http_response_code( $this->client->status_code );
            throw new Exception( $err );
        }
    }
}
