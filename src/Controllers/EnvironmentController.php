<?php

namespace RachidLaasri\LaravelInstaller\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RachidLaasri\LaravelInstaller\Events\EnvironmentSaved;
use RachidLaasri\LaravelInstaller\Helpers\EnvironmentManager;
use Validator;

class EnvironmentController extends Controller
{
    /**
     * @var EnvironmentManager
     */
    protected $EnvironmentManager;

    /**
     * @param EnvironmentManager $environmentManager
     */
    public function __construct(EnvironmentManager $environmentManager)
    {
        $this->EnvironmentManager = $environmentManager;
    }

    /**
     * Display the Environment menu page.
     *
     * @return \Illuminate\View\View
     */
    public function environmentMenu()
    {
        return view('vendor.installer.environment');
    }

    /**
     * Display the Environment page.
     *
     * @return \Illuminate\View\View
     */
    public function environmentWizard()
    {
        $envConfig = $this->EnvironmentManager->getEnvContent();

        return view('vendor.installer.environment-wizard', compact('envConfig'));
    }

    /**
     * Processes the newly saved environment configuration (Classic).
     *
     * @param Request $input
     * @param Redirector $redirect
     * @return \Illuminate\Http\RedirectResponse
     */
    public function saveClassic(Request $input, Redirector $redirect)
    {
        $message = $this->EnvironmentManager->saveFileClassic($input);
        event(new EnvironmentSaved($input));

        $purchaseCode = env('PURCHASE_CODE', false);

        if (!$this->isValidPurchaseCode($purchaseCode)) {
            return $redirect->route('LaravelInstaller::environmentClassic')
                ->with(['message' => $message, 'errors' => 'The purchase key is invalid.']);
        }

        $validation = $this->verifyLicenseWithAPI($purchaseCode, url('/'));
        if (!$validation['success']) {
            return $redirect->route('LaravelInstaller::environmentClassic')
                ->with(['message' => $message, 'errors' => $validation['message']]);
        }

        return $redirect->route('LaravelInstaller::environmentClassic')->with(['message' => $message]);
    }

    /**
     * Processes the newly saved environment configuration (Form Wizard).
     *
     * @param Request $request
     * @param Redirector $redirect
     * @return \Illuminate\Http\RedirectResponse
     */
    public function saveWizard(Request $request, Redirector $redirect)
    {
        $rules = config('installer.environment.form.rules');
        $messages = [
            'environment_custom.required_if' => trans('installer_messages.environment.wizard.form.name_required'),
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return $redirect->route('LaravelInstaller::environmentWizard')->withInput()->withErrors($validator->errors());
        }

        $results = $this->EnvironmentManager->saveFileWizard($request);

        if (!$this->checkDatabaseConnection($request)) {
            $errors = $validator->errors()->add('database_connection', trans('installer_messages.environment.wizard.form.db_connection_failed'));
            return view('vendor.installer.environment-wizard', compact('errors'));
        }

        event(new EnvironmentSaved($request));
    
        $purchaseCode = $request->get('purchase_code');
        if (!$this->isValidPurchaseCode($purchaseCode)) {
            $errors = $validator->errors()->add('purchase_code', 'The purchase key is invalid.');
            return view('vendor.installer.environment-wizard', compact('errors'));
        }

        $validation = $this->verifyLicenseWithAPI($purchaseCode, url('/'));
        if (!$validation['success']) {
            $errors = $validator->errors()->add('purchase_code', $validation['message']);
            return view('vendor.installer.environment-wizard', compact('errors'));
        }

        return $redirect->route('LaravelInstaller::database')->with(['results' => $results]);
    }

    private function verifyLicenseWithAPI($purchaseCode, $url)
    {
        try {
            $response = Http::post('https://license.devjamv.com/api/verify', [
                'purchase_code' => $purchaseCode,
                'url' => $url
            ]);

            if ($response->failed()) {
                return [
                    'success' => false,
                    'message' => json_decode($response->body(), true)['error'] ?? 'Failed to validate purchase code.',
                ];
            }

            $data = json_decode($response->body(), true);
            return [
                'success' => true,
                'site_key' => $data['SITE_KEY'] ?? '',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Unable to connect to the license server. Please try again later.',
            ];
        }
    }

    private function isValidPurchaseCode($code)
    {
        return preg_match("/^(\w{8})-((\w{4})-){3}(\w{12})$/", $code);
    }

    /**
     * TODO: We can remove this code if PR will be merged: https://github.com/RachidLaasri/LaravelInstaller/pull/162
     * Validate database connection with user credentials (Form Wizard).
     *
     * @param Request $request
     * @return bool
     */
    private function checkDatabaseConnection(Request $request)
    {
        $connection = $request->input('database_connection');

        $settings = config("database.connections.$connection");

        config([
            'database' => [
                'default' => $connection,
                'connections' => [
                    $connection => array_merge($settings, [
                        'driver' => $connection,
                        'host' => $request->input('database_hostname'),
                        'port' => $request->input('database_port'),
                        'database' => $request->input('database_name'),
                        'username' => $request->input('database_username', ''),
                        'password' => $request->input('database_password', ''),
                    ]),
                ],
            ],
        ]);

        try {
            DB::purge($connection);
            DB::reconnect();
            DB::connection()->getPdo();

            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
