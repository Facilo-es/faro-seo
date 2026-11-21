<?php

namespace facilo\faro\Craft;

use Craft;
use facilo\faro\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {% hook "faro" %}, and Ether SEO's {% hook "seo" %} and getSeoField()
 * while Ether itself isn't installed (F9).
 */
class TwigExtension extends AbstractExtension
{
    public function __construct(private bool $etherCompat)
    {
        $view = Craft::$app->getView();
        $view->hook('faro', fn(array &$context) => Plugin::getInstance()->meta->hook($context));
        if ($etherCompat) {
            $view->hook('seo', fn(array &$context) => Plugin::getInstance()->meta->hook($context));
        }
    }

    public function getFunctions(): array
    {
        $functions = [
            new TwigFunction('faroField', [$this, 'getSeoField'], ['needs_context' => true]),
        ];
        if ($this->etherCompat) {
            $functions[] = new TwigFunction('getSeoField', [$this, 'getSeoField'], ['needs_context' => true]);
        }
        return $functions;
    }

    /**
     * The SEO field value of the page in context, as Ether finds it: the
     * variable named after the handle, then entry, product and category.
     *
     * @param array<string, mixed> $context
     */
    public function getSeoField(array $context, string $handle = 'seo'): ?FieldValue
    {
        if (($context[$handle] ?? null) instanceof FieldValue) {
            return $context[$handle];
        }
        foreach (['entry', 'product', 'category'] as $key) {
            $element = $context[$key] ?? null;
            if ($element instanceof \craft\base\ElementInterface) {
                $layout = $element->getFieldLayout();
                $field = $layout?->getFieldByHandle($handle);
                if ($field !== null) {
                    $value = $element->getFieldValue($handle);
                    return $value instanceof FieldValue ? $value : null;
                }
            }
        }
        return null;
    }
}
