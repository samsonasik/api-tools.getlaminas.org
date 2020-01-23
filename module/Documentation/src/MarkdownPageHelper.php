<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2015 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace Documentation;

use League\CommonMark\Converter;
use League\CommonMark\DocParser;
use League\CommonMark\Environment;
use League\CommonMark\HtmlRenderer;
use Webuni\CommonMark\TableExtension\TableExtension;
use Laminas\Filter\FilterChain;
use Laminas\View\Helper\HelperInterface;
use Laminas\View\Helper\Url as UrlHelper;
use Laminas\View\Renderer\RendererInterface;

class MarkdownPageHelper implements HelperInterface
{
    /**
     * @var FilterChain;
     */
    protected $anchorFilterChain;

    /**
     * @var Converter
     */
    protected $parser;

    /**
     * @var UrlHelper
     */
    protected $url;

    /**
     * @param UrlHelper $url
     */
    public function __construct(UrlHelper $url)
    {
        $environment = Environment::createCommonMarkEnvironment();
        $environment->addExtension(new TableExtension());

        $this->parser = new Converter(new DocParser($environment), new HtmlRenderer($environment));
        $this->url    = $url;
    }

    /**
     * Invoke as a function
     *
     * @param string $page
     * @param DocumentationModel $model
     * @param bool $highlightContents
     * @return string
     * @throws \RuntimeException for non-string input
     */
    public function __invoke($page, DocumentationModel $model, $highlightContents = true)
    {
        $contents = $model->getPageContents($page);

        // transform markdown to HTML
        $html = $this->parser->convertToHtml($contents);

        // transform language-HTTP to language-http
        $html = str_replace('language-HTTP', 'language-http', $html);

        // transform language-JSON, language-json, and language-js to language-javascript
        $html = str_replace(['language-JSON', 'language-json', 'language-js'], 'language-javascript', $html);

        // transform language-console, language-sh to language-bash
        $html = str_replace(['language-console', 'language-sh'], 'language-bash', $html);

        // transform links
        $html = preg_replace_callback(
            '#(?P<attr>src|href)="/(?P<link>[^"]+)"#s',
            [$this, 'rewriteLinks'],
            $html
        );

        // add anchors to headers
        $html = preg_replace_callback(
            '#<(?P<header>h[1-4])>(?P<content>.*?)</\1>#s',
            [$this, 'addAnchorsToHeaders'],
            $html
        );

        return $html;
    }

    /**
     * @param array $matches
     * @return string
     */
    public function rewriteLinks(array $matches)
    {
        $attr = $matches['attr'];
        $link = '/' . preg_replace('%^(?:asset/)?(.*?)(?:\.md)?(?P<anchor>#[a-z0-9_-]+)?$%i', '$1', $matches['link']);

        // Non-asset link needs to be relative to documentation route
        if (0 !== strpos($matches['link'], 'asset/')) {
            $link = $this->url->__invoke('documentation') . $link;
        }

        if (isset($matches['anchor']) && ! empty($matches['anchor'])) {
            $link .= $matches['anchor'];
        }

        return sprintf('%s="%s"', $attr, $link);
    }

    /**
     * @param array $matches
     * @return string
     */
    public function addAnchorsToHeaders(array $matches)
    {
        $header  = $matches['header'];
        $content = $matches['content'];
        $name    = $this->getAnchorFilterChain()->filter($content);

        return sprintf('<%s><a name="%s"></a>%s</%s>', $header, $name, $content, $header);
    }

    /**
     * @return FilterChain
     */
    protected function getAnchorFilterChain()
    {
        if ($this->anchorFilterChain instanceof FilterChain) {
            return $this->anchorFilterChain;
        }

        $chain = new FilterChain();
        $chain->attachByName('StripTags');
        $chain->attachByName('PregReplace', ['pattern' => '/[^a-z0-9_ -]/i', 'replacement' => '']);
        $chain->attachByName('WordSeparatorToDash'); // Uses " " as separator by default
        $chain->attachByName('StringToLower');

        $this->anchorFilterChain = $chain;
        return $this->anchorFilterChain;
    }

    /**
     * @var RendererInterface
     */
    protected $renderer;

    /**
     * Implement HelperInterface
     *
     * @param RendererInterface $renderer
     */
    public function setView(RendererInterface $renderer)
    {
        $this->renderer = $renderer;
    }

    /**
     * Implement HelperInterface
     *
     * @return RendererInterface
     */
    public function getView()
    {
        return $this->renderer;
    }
}
