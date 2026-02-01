<?php

/**
 * 一个简单的字体分包加载插件，用于部署外部字体
 *
 * @package SimpleFonts
 * @version 1.1.2
 * @author MoXiify
 */

class SimpleFonts_Plugin implements Typecho_Plugin_Interface
{
    /** 
     * PureSuck-theme 官方推荐字体作用范围
     * 仅在启用联动，且用户未手动接管 selector 时生效
     */
    const PURESUCK_SELECTOR =
    'body,
.post--cover .post-body .post-wrapper > p:first-child,
.comment-title,
h1, h2, h3, h4, h5, h6';

    /**
     * 内置字体预设
     * 作为“字体来源”的一种选择，不影响 selector 逻辑
     */
    const FONT_PRESETS = [
        'lxgw-wenkai' => [
            'label'  => '霞雾文楷',
            'family' => 'LXGW WenKai',
            'weight' => 'normal',
            'cdn' => [
                'zeoseven' => [
                    'label' => 'ZeoSeven CDN',
                    'url'   => 'https://fontsapi.zeoseven.com/292/main/result.css',
                ],
                'deno' => [
                    'label' => 'Deno CDN',
                    'url'   => 'https://chinese-fonts-cdn.deno.dev/packages/lxgwwenkai/dist/LXGWWenKai-Regular/result.css',
                ],
            ],
        ],
    ];

    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Archive')->header = ['SimpleFonts_Plugin', 'run'];
        return _t('SimpleFonts 已激活。');
    }

    public static function deactivate()
    {
        return _t('SimpleFonts 已禁用。');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        /* 字体来源 */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'fontSource',
            ['preset' => '使用内置预设字体', 'custom' => '使用自定义字体'],
            'preset',
            _t('字体来源'),
            _t(
                '决定字体从哪里加载。<br>
                选择「内置预设字体」时，下方所有自定义字体配置将不会生效。'
            )
        ));

        /* 预设字体 */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'fontPreset',
            ['lxgw-wenkai' => '霞雾文楷（LXGW WenKai）'],
            'lxgw-wenkai',
            _t('字体预设'),
            _t('仅在「字体来源 = 使用内置预设字体」时生效')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'presetCdn',
            ['zeoseven' => 'ZeoSeven CDN（推荐）', 'deno' => '中文网字计划 CDN'],
            'zeoseven',
            _t('预设 CDN 源'),
            _t('仅在使用预设字体时生效')
        ));

        /* 自定义字体 */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'fontUrl',
            null,
            '',
            _t('自定义字体 CSS 地址'),
            _t('仅在「字体来源 = 使用自定义字体」时生效')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'fontFamily',
            null,
            '',
            _t('自定义 font-family'),
            _t('例如：Inter、LXGW WenKai')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'fontWeight',
            null,
            '',
            _t('自定义 font-weight（可选）'),
            _t('可留空，不建议强制设置')
        ));

        /* 字体作用范围 */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'targetSelector',
            null,
            'body',
            _t('字体作用范围（CSS Selector）'),
            _t(
                '清空或填写 body 将恢复为默认行为。<br>
                常见示例：<code>body</code>、<code>body, h1, h2, h3, h4, h5, h6</code>'
            )
        ));

        /*  PureSuck 联动 */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'enablePureSuck',
            ['0' => '关闭', '1' => '启用'],
            '0',
            _t('PureSuck-theme 联动'),
            _t(
                '启用后，在你未手动接管作用范围的前提下，<br>
                将自动使用 PureSuck 主题推荐的字体作用范围。'
            )
        ));

        /* 加载方式 */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'loadMode',
            ['preload' => '预加载（推荐）', 'blocking' => '普通加载'],
            'preload',
            _t('字体加载方式'),
            _t(
                '预加载：提前下载字体资源，减少渲染等待时间。<br>
                普通加载：按默认顺序加载，适合对加载顺序有特殊要求的场景。'
            )
        ));
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    public static function run()
    {
        $opt = Typecho_Widget::widget('Widget_Options')->plugin('SimpleFonts');

        $fontSource = (string)$opt->fontSource;
        $rawSelector = trim((string)$opt->targetSelector);
        $usePure = ((string)$opt->enablePureSuck === '1');
        $loadMode = (string)$opt->loadMode;

        /**
         * 状态机设计：
         * - 默认 selector = body
         * - selector 为空 或 等于 body → 视为默认
         * - selector 非空 且 ≠ body → 视为用户接管
         * - PureSuck 联动仅在“默认态”生效
         */
        if ($rawSelector === '' || $rawSelector === 'body') {
            if ($usePure) {
                $selector = self::PURESUCK_SELECTOR;
            } else {
                $selector = 'body';
            }
        } else {
            // 用户明确输入，立即接管
            $selector = preg_replace('/\s+/', ' ', $rawSelector);
        }

        /* 字体来源：内置预设 */
        if ($fontSource === 'preset') {
            $preset = self::FONT_PRESETS[$opt->fontPreset] ?? null;
            if (!$preset) return;

            $cdn = $preset['cdn'][$opt->presetCdn] ?? null;
            if ($cdn) {
                $url = $cdn['url'];
                $host = parse_url($url, PHP_URL_HOST);

                if ($loadMode === 'preload') {
                    echo '<link rel="preconnect" href="https://' . $host . '" crossorigin>' . "\n";
                    echo '<link rel="preload" href="' . $url . '" as="style">' . "\n";
                }
                echo '<link rel="stylesheet" href="' . $url . '">' . "\n";
            }

            echo "<style>\n";
            echo "{$selector} { font-family: '{$preset['family']}', sans-serif !important; font-weight: {$preset['weight']}; }\n";
            echo "</style>\n";
            return;
        }

        /* 字体来源：自定义 */
        $fontUrl = trim((string)$opt->fontUrl);
        $family  = trim((string)$opt->fontFamily);
        $weight  = trim((string)$opt->fontWeight);

        if ($family === '') return;

        if ($fontUrl !== '') {
            if ($loadMode === 'preload') {
                $host = parse_url($fontUrl, PHP_URL_HOST);
                if ($host) {
                    echo '<link rel="preconnect" href="https://' . $host . '" crossorigin>' . "\n";
                }
                echo '<link rel="preload" href="' . $fontUrl . '" as="style">' . "\n";
            }
            echo '<link rel="stylesheet" href="' . $fontUrl . '">' . "\n";
        }

        echo "<style>\n";
        echo "{$selector} { font-family: '{$family}', sans-serif !important;";
        if ($weight !== '') echo " font-weight: {$weight};";
        echo " }\n</style>\n";
    }
}
