<?php

if (!defined('_PS_VERSION_')) {
    return;
}

class edelweiss_check3DS extends Module
{

    public function __construct()
    {
        $this->name = 'edelweiss_check3DS';
        $this->author = 'e-delweiss';
        $this->tab = 'administration';
        $this->version = '1.0';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Check 3DS');
        $this->description = $this->l('Check for a new order if 3DS is ok.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall this module ?');
    }

    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (!parent::install() ||
            !$this->registerHook('actionObjectMessageAddAfter') ||
            !$this->registerHook('displayAdminOrder') ||
            !$this->registerHook('displayPDFInvoice') ||
            !Configuration::updateValue('3DSCHECK_MAILS', Configuration::get('PS_SHOP_EMAIL')) ||
            !Configuration::updateValue('3DSCHECK_MESSAGE', 'Authentification 3DS : NON')
        )
            return false;
        return true;
    }

    public function uninstall()
    {
        if (!parent::uninstall() ||
            !Configuration::deleteByName('3DSCHECK_MAILS') ||
            !Configuration::deleteByName('3DSCHECK_MESSAGE')
        )
            return false;
        return true;
    }

    public function getContent()
    {
        $this->html = '';
        if (Tools::isSubmit('submit'.$this->name))
        {
            $errors = $this->postProcess();
            if (count($errors) > 0)
                $this->html .= $this->displayError(implode('<br />', $errors));
            else
                $this->html .= $this->displayConfirmation($this->l('Settings updated successfully'));
        }
        $this->html .= $this->renderForm();

        return $this->html;
    }

    public function hookDisplayAdminOrder($params) {
        if ($this->checkmessages($params['id_order'],Configuration::get('3DSCHECK_MESSAGE')))
            return '<p class="alert alert-danger" style="clear:both;">'.$this->l('Be careful, this order is not guaranted with 3Dsecure !').'</p>';
    }

    public function hookDisplayPDFInvoice($params) {
        if ($this->checkmessages($params['object']->id_order,Configuration::get('3DSCHECK_MESSAGE')))
            return '<p class="alert alert-danger" style="clear:both;">'.$this->l('Be careful, this order is not guaranted with 3Dsecure !').'</p>';
    }

    public function hookActionObjectMessageAddAfter($params) {
        if (strpos ($params['object']->message, Configuration::get('3DSCHECK_MESSAGE')) !== false)
            $this->sendmails($params['object']->id_order);
    }

    public function checkmessages($id_order,$message) {
        $sql = 'SELECT id_message
                FROM `' . _DB_PREFIX_ . 'message`
                WHERE id_order = ' . (int)$id_order .'
                AND message LIKE "%'.$message.'%"';

        return Db::getInstance()->getValue($sql);

    }

    public function postProcess() {
        $errors=array();
        $emails = (string)Tools::getValue('3DSCHECK_MAILS');
        $message = (string)Tools::getValue('3DSCHECK_MESSAGE');

        if (!$message || empty($message))
            $errors[] = $this->l('Please type one message to check');
        else {
            if (!Configuration::updateValue('3DSCHECK_MESSAGE', (string)$message))
                $errors[] = $this->l('Cannot update settings');
        }

        if (!$emails || empty($emails))
            $errors[] = $this->l('Please type one (or more) e-mail address');
        else {
            $emails = str_replace(';', '/n', $emails);
            $emails = explode(PHP_EOL, $emails);
            $emails = array_map('trim', $emails);

            foreach ($emails as $k => $email) {
                $email = trim($email);
                if (!empty($email) && !Validate::isEmail($email)) {
                    $errors[] = $this->l('Invalid e-mail:') . ' ' . Tools::safeOutput($email);
                    unset($emails[$k]);
                    break;
                } elseif (!empty($email) && count($email) > 0)
                    $emails[$k] = $email;
                else
                    unset($emails[$k]);
            }

            $emails = implode(';', $emails);

            if (!Configuration::updateValue('3DSCHECK_MAILS', (string)$emails))
                $errors[] = $this->l('Cannot update settings');
        }

        return $errors;
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Check 3DS'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Message to check'),
                        'name' => '3DSCHECK_MESSAGE',
                        'size' => 40,
                        'desc' => $this->l('Text message the module must check.'),
                    ),
                    array(
                        'type' => 'textarea',
                        'cols' => 36,
                        'rows' => 4,
                        'label' => $this->l('E-mail addresses'),
                        'name' => '3DSCHECK_MAILS',
                        'desc' => $this->l('One e-mail address per line (e.g. bob@example.com).'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitcheck3DS',
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->module = $this;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit'.$this->name;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name
            .'&tab_module='.$this->tab
            .'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    public function getValues() {
        $emails = Tools::getValue('3DSCHECK_MAILS', Configuration::get('3DSCHECK_MAILS'));
        $message = Tools::getValue('3DSCHECK_MESSAGE', Configuration::get('3DSCHECK_MESSAGE'));
        return array (
            '3DSCHECK_MESSAGE' => $message,
            '3DSCHECK_MAILS' => str_replace(";","\n",$emails)
        );
    }

    public function sendMails($id_order) {
        $context = Context::getContext();
        $iso = Language::getIsoById($context->language->id);
        $id_lang = (int)$context->language->id;
        $id_shop = (int)$context->shop->id;
        $template_vars = array(
            '{id_order}' => $id_order
        );

        if (
            file_exists(dirname(__FILE__).'/mails/'.$iso.'/3DSalert.txt') &&
            file_exists(dirname(__FILE__).'/mails/'.$iso.'/3DSalert.html'))
        {
            $mails = explode(';', Configuration::get('3DSCHECK_MAILS'));

            foreach ($mails as $mail)
            {
                Mail::Send(
                    $id_lang,
                    '3DSalert',
                    Mail::l('Alert unsecure order', $id_lang),
                    $template_vars,
                    $mail,
                    null,
                    (string)Configuration::get('PS_SHOP_EMAIL'),
                    (string)Configuration::get('PS_SHOP_NAME'),
                    null,
                    null,
                    dirname(__FILE__).'/mails/',
                    false,
                    $id_shop
                );
            }
        }
    }
}