<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

class Seocrawleraudit extends Module implements WidgetInterface
{
    public function __construct()
    {
        $this->name = 'seocrawleraudit';
        $this->author = 'SEO Team';
        $this->version = '1.0.0';
        $this->tab = 'seo';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('SEO Crawler Audit', [], 'Modules.Seocrawleraudit.Admin');
        $this->description = $this->trans(
            'Crawl catalogue pages and detect technical/on-page SEO issues.',
            [],
            'Modules.Seocrawleraudit.Admin'
        );

        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->installSql()
            && $this->installTab()
            && Configuration::updateValue('SEOCRAWLER_THIN_CONTENT_MIN_WORDS', 150);
    }

    public function uninstall()
    {
        return $this->uninstallTab()
            && $this->uninstallSql()
            && Configuration::deleteByName('SEOCRAWLER_THIN_CONTENT_MIN_WORDS')
            && parent::uninstall();
    }

    public function getContent()
    {
        $adminLink = $this->context->link->getAdminLink('AdminSeoCrawlerAudit');

        Tools::redirectAdmin($adminLink);

        return '';
    }

    public function renderWidget($hookName, array $configuration)
    {
        return '';
    }

    public function getWidgetVariables($hookName, array $configuration)
    {
        return [];
    }

    private function installSql()
    {
        $queries = file_get_contents(__DIR__ . '/sql/install.sql');
        $queries = str_replace(['PREFIX_', 'ENGINE_TYPE'], [_DB_PREFIX_, _MYSQL_ENGINE_], (string) $queries);

        return Db::getInstance()->execute($queries);
    }

    private function uninstallSql()
    {
        $queries = file_get_contents(__DIR__ . '/sql/uninstall.sql');
        $queries = str_replace('PREFIX_', _DB_PREFIX_, (string) $queries);

        return Db::getInstance()->execute($queries);
    }

    private function installTab()
    {
        $tab = new Tab();
        $tab->active = true;
        $tab->class_name = 'AdminSeoCrawlerAudit';
        $tab->name = [];

        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'SEO Crawler Audit';
        }

        $tab->id_parent = (int) Tab::getIdFromClassName('CONFIGURE');
        $tab->module = $this->name;

        return $tab->add();
    }

    private function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminSeoCrawlerAudit');

        if ($idTab <= 0) {
            return true;
        }

        $tab = new Tab($idTab);

        return $tab->delete();
    }
}
