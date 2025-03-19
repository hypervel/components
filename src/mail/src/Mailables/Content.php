<?php

declare(strict_types=1);

namespace Hypervel\Mail\Mailables;

use Hyperf\Conditionable\Conditionable;

class Content
{
    use Conditionable;

    /**
     * Create a new content definition.
     *
     * @param null|string $view the Blade view that should be rendered for the mailable
     * @param null|string $html The Blade view that should be rendered for the mailable. (Alternative syntax for "view".)
     * @param null|string $text the Blade view that represents the text version of the message
     * @param null|string $markdown the Blade view that represents the Markdown version of the message
     * @param array $with the message's view data
     * @param null|string $htmlString the pre-rendered HTML of the message
     */
    public function __construct(
        public ?string $view = null,
        public ?string $html = null,
        public ?string $text = null,
        public ?string $markdown = null,
        public array $with = [],
        public ?string $htmlString = null
    ) {
    }

    /**
     * Set the view for the message.
     */
    public function view(string $view): static
    {
        $this->view = $view;

        return $this;
    }

    /**
     * Set the view for the message.
     */
    public function html(string $view): static
    {
        return $this->view($view);
    }

    /**
     * Set the plain text view for the message.
     */
    public function text(string $view): static
    {
        $this->text = $view;

        return $this;
    }

    /**
     * Set the Markdown view for the message.
     */
    public function markdown(string $view): static
    {
        $this->markdown = $view;

        return $this;
    }

    /**
     * Set the pre-rendered HTML for the message.
     */
    public function htmlString(string $html): static
    {
        $this->htmlString = $html;

        return $this;
    }

    /**
     * Add a piece of view data to the message.
     */
    public function with(array|string $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->with = array_merge($this->with, $key);
        } else {
            $this->with[$key] = $value;
        }

        return $this;
    }
}
