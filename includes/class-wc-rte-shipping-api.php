<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RteShippingAPI
{
    /**
	 * API URL.
	 *
	 * @var string
	 */
    protected $get_token_url        = 'https://01wapi.rte.com.br/token';
    protected $get_city_id_url      = 'https://01wapi.rte.com.br/api/v1/busca-por-cep';
    protected $simulate_url         = 'https://01wapi.rte.com.br/api/v1/simula-cotacao';

	/**
	 * API Security
	 *
	 * @var string
	 */
	protected $username                 = '';
	protected $password                 = '';
    protected $token                    = '';

    /**
	 * API constructor.
	 *
	 * @param string $username
	 * @param string $password
	 */
    
	public function __construct( $username, $password, $costumer_registration ) {
		$this->username              = $username;
		$this->password              = $password;
        $this->costumer_registration = $this->only_numbers( $costumer_registration );
        $this->token                 = get_option( 'RTE_JWT' );
	}

    protected function only_numbers( $string ) {
		return preg_replace( '([^0-9])', '', $string );
	}

    protected function get_token() {     
        $access_header = array('Content-Type: application/x-www-form-urlencoded',);
        $access_body = 'auth_type=DEV&grant_type=password&username=' . $this->username . '&password=' . $this->password ;

        $response = $this->request_api($this->get_token_url, 'POST', $access_header, $access_body );

        if ( array_key_exists('access_token', $response ) ) {
            update_option( 'RTE_JWT', $response['access_token'] );
            $this->token = get_option( 'RTE_JWT' );
        } else {
            echo 'Authorization has been denied for this request.';
        }
    }

    protected function refresh_token( $response ) {
        if ( array_key_exists( 'Message', $response ) && $response['Message'] == 'Authorization has been denied for this request.' ) { 
            $this->get_token();
            return true;          
        } elseif ( array_key_exists( 'Message', $response ) ) {
            echo $response['Message'];
            return true;
        } else {
            return false;
        }     
    }

    protected function request_api( $url, $request_type, $header, $body ) {
        $curl = curl_init();

        curl_setopt_array( $curl, [
            CURLOPT_URL             => $url,          
            CURLOPT_RETURNTRANSFER  => true,          
            CURLOPT_ENCODING        => '',          
            CURLOPT_MAXREDIRS       => 10,          
            CURLOPT_TIMEOUT         => 30,          
            CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,          
            CURLOPT_CUSTOMREQUEST   => $request_type, 
            CURLOPT_HTTPHEADER      => $header,         
            CURLOPT_POSTFIELDS      => $body ,
        ]);

        $response = curl_exec( $curl );
        $err = curl_error( $curl );
        curl_close( $curl );

        $response = json_decode( $response, true );

        if ($err) {
            return "cURL Error #:" . array( 'Message' => $err );
        } else {
            return $response;  
        }
    }

    public function get_simple_shipping_data( $package, $origin_data, $destination_data ) {           
        if ( array_key_exists( 'product_page', $package ) ) {
            $product_page = true;
        } else {
            $product_page = false;
        }

        $data = array(
            'CustomerTaxIdRegistration' => $this->costumer_registration,
            'OriginZipCode'             => $origin_data['postcode'],
            'OriginCityId'              => $origin_data['id'],
            'DestinationZipCode'        => $destination_data['postcode'],
            'DestinationCityId'         => $destination_data['id'],
            'EletronicInvoiceValue'     => $package['cart_subtotal'],
            'TotalWeight'               => ( $product_page ? $package['contents']['Weight'] : WC()->cart->cart_contents_weight ),
            'Packs'                     => ( $product_page ? array( $package['contents'] ): $this->get_package_data( $package ) ),
        );

        return $data;
    }

    public function shipping_simulation( $package = array(), $origin_data ) {
        $header = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
            'Content-Type: text/json'
        );

        if ( ! $package['destination']['postcode'] ) {
            return null;
        }

        $destination_data = $this->get_postcode_data( $package['destination']['postcode'] );

        if ( is_null( $destination_data ) ) {
            return 'Postcode is not valid.';
        }
        
        $body = $this->get_simple_shipping_data( $package, $origin_data, $destination_data );
        $body = json_encode( $body );

        $response = $this->request_api( $this->simulate_url, 'POST', $header, $body );

        if ( $this->refresh_token( $response ) ) { 
            $header = array(
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
                'Content-Type: text/json'
            );
            $response = $this->request_api( $this->simulate_url, 'POST', $header, $body );
        }
        
        return $response;      
    }

    public function get_postcode_data( $zip_code ) {
        if ( $zip_code == '' ) {
            return null;
        }
        $header = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
        );

        $url = $this->get_city_id_url . '?zipCode=' . $this->only_numbers( $zip_code );

        $response = $this->request_api( $url, 'GET', $header, '' );

        if ( $this->refresh_token( $response ) ) { 
            $header = array(
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            );
            $response = $this->request_api( $url, 'GET', $header, '' );
        }

        if ( array_key_exists( '0', $response ) && $response['0']['Message'] == 'CEP não encontrado' ) {
            return $response['0']['Message'];
        }

        $data = array(
            'id'            => $response['CityId'],
            'name'          => $response['CityDescription'],
            'state'         => $response['UnitFederation']['Description'],
            'district'      => $response['District'],
            'street'        => $response['Street'],
            'postcode'      => $response['ZipCode']
        );

        return $data;
    }

    function get_package_data( $package ) {
        $pack = array();

        foreach ( $package['contents'] as $item_id => $value ) {
            $product = $value['data']; 
            $qty     = $value['quantity'];

            if ( $qty > 0 && $product->needs_shipping() ) {
                $product_data = array( 
                    'AmountPackages'    => (int) $qty,
                    'Weight'            => (float) $product->get_weight(),
                    'Length'            => (float) $product->get_length(),
                    'Height'            => (float) $product->get_height(),
                    'Width'             => (float) $product->get_width()
                );

                array_push( $pack, $product_data ); 
            }
        }
           
        return $pack;
    }
}
