<?php
require('../../../init.php');
include('../../../includes/functions.php');
include('../../../includes/gatewayfunctions.php');
require(__DIR__ . '/billplz-api.php');

global $CONFIG;

use Illuminate\Database\Capsule\Manager as Capsule;

define('CLIENTAREA', true);
//define('FORCESSL', true); // Uncomment to force the page to use https://
// Load Billplz Payment Gateway parameter
$gatewayParams = getGatewayVariables('billplzPay');

$ca = new WHMCS_ClientArea();

//$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
//$ca->addToBreadCrumb('mypage.php', 'Your Custom Page Name');

$ca->initPage();

//$ca->requireLogin(); // Uncomment this line to require a login to access this page
# To assign variables to the template system use the following syntax.
# These can then be referenced using {$variablename} in the template.
//$ca->assign('variablename', $value);
# Check client or admin login status
if ($ca->isLoggedIn() || $_SESSION['adminid']) {

    # User is logged in - put any code you like here
    # Here's an example to get the currently logged in clients first name
    //$clientName = Capsule::table('tblclients')
    //                ->where('id', '=', $ca->getUserID())->value('firstname');
    // 'pluck' was renamed within WHMCS 7.0.  Replace it with 'value' instead.
    // ->where('id', '=', $ca->getUserID())->value('firstname');
    //$ca->assign('clientname', $clientName);
    // Prepare data that need to be sent

    $api_key = $gatewayParams['billplz_api_key'];
    $collection_id = $gatewayParams['billplz_collection_id'];
    $x_signature = $gatewayParams['billplz_x_signature_key'];
    $deliver = $gatewayParams['billplz_deliver'];

    $email = isset($_POST['email']) ? $_POST['email'] : die('Email parameter is not passed');
    $mobile = isset($_POST['mobile']) ? $_POST['mobile'] : die('Mobile parameter is not passed');
    $name = isset($_POST['name']) ? $_POST['name'] : die('Name parameter is not passed');
    $amount = isset($_POST['amount']) ? $_POST['amount'] : die('Amount parameter is not passed');
    $description = isset($_POST['description']) ? $_POST['description'] : die('Description parameter is not passed');
    $reference_1 = isset($_POST['invoiceid']) ? $_POST['invoiceid'] : die('Invoice parameter is not passed');
    $userid = isset($_POST['userid']) ? $_POST['userid'] : die('Userid parameter is not passed');
    $baseCurrencyAmount = isset($_POST['basecurrencyamount']) ? $_POST['basecurrencyamount'] : die('BaseCurrencyAmount parameter is not passed');
    $baseCurrency = isset($_POST['basecurrency']) ? $_POST['basecurrency'] : die('BaseCurrency parameter is not passed');

    $hash = isset($_POST['hash']) ? $_POST['hash'] : die('Hash parameter is not passed');
    $raw_string = $amount . $reference_1 . $userid . $baseCurrencyAmount;
    $filtered_string = preg_replace("/[^a-zA-Z0-9]+/", "", $raw_string);
    $new_hash = hash_hmac('sha256', $filtered_string, $x_signature);

    if ($hash !== $new_hash) {
        exit('Calculated Hash does not valid. Contact developer for more information.');
    }

    $redirect_url = $CONFIG['SystemURL'] . '/modules/gateways/billplzPay/billplzReturn.php';
    $callback_url = $CONFIG['SystemURL'] . '/modules/gateways/callback/billplzCallback.php';
    $billplz = new Billplz($api_key);

    /*
    * Added to achieve 1 invoice, 1 bill as possible
    */
    $sqlquery = Capsule::table('mod_billplz_gateway')
                        ->where('userid', $userid)
                        ->where('invoiceid', $reference_1)
                        ->get();
    foreach ($sqlquery as $data) {
        $database_url = $data->billurl;
        $database_amount = $data->amount;
        $database_name = $data->name;
        $database_email = $data->email;
        $database_billid = $data->billid;
        unset($data);

        if ($database_amount === $amount && $database_name === $name && $database_email === $email) {
            header('Location: ' . $database_url);
            exit;
        } else {
            Capsule::table('mod_billplz_gateway')
                ->where('userid', $userid)
                ->where('invoiceid', $reference_1)
                ->delete();
            $billplz->deleteBill($database_billid);
        }

        break;
    }

    $billplz
        ->setAmount($amount)
        ->setCollection($collection_id)
        ->setDeliver($deliver)
        ->setDescription($description)
        ->setEmail($email)
        ->setMobile($mobile)
        ->setName($name)
        ->setPassbackURL($callback_url, $redirect_url)
        ->setReference_1($reference_1)
        ->setReference_1_Label('ID')
        ->setReference_2_Label($baseCurrency)
        ->setReference_2($baseCurrencyAmount)
        ->create_bill(true);
    $url = $billplz->getURL();

    if (empty($url)) {
        exit('Something went wrong! ' . $billplz->getErrorMessage());
    } else {
        /*
        * Added to achieve 1 invoice, 1 bill as possible
        */
        Capsule::table('mod_billplz_gateway')->insert(
                [
                    'userid' => $userid,
                    'invoiceid' => $reference_1,
                    'billurl' => $url,
                    'billid' => $billplz->getID(),
                    'amount' => $amount,
                    'name' => $name,
                    'email' => $email
                ]
        );
    }

    header('Location: ' . $url);
} else {
    header('Location: ' . $CONFIG['SystemURL']);
}
