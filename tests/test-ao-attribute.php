<?php
use PHPUnit\Framework\TestCase;

require_once 'classes/autoptimizeAttributeParser.php';

class test_ao_attribute extends TestCase {

    public function test_extracts_single_attribute_value() {
        $tag = '<img src="https://example.com/logo.png" alt="Logo">';
        $actual = autoptimizeAttributeParser::get_attribute($tag, 'img', 'src');
        $this->assertEquals('https://example.com/logo.png', $actual);
    }

    public function test_extracts_multiple_attributes_via_regex() {
        $tag = '<div data-src="img.jpg" data-lazy="true" class="box">';
        $attrs = autoptimizeAttributeParser::get_attributes($tag, 'div', '/^data-/');

        $this->assertArrayHasKey('data-src', $attrs);
        $this->assertEquals('true', $attrs['data-lazy']);
        $this->assertArrayNotHasKey('class', $attrs);
    }

    public function test_modifies_single_attribute_via_callback() {
        $tag = '<img title="image.jpg">';
        $output = autoptimizeAttributeParser::modify_attribute($tag, 'img', 'title', function($name, $value) {
            return "cdn-" . $value;
        });
        $this->assertStringContainsString('title="cdn-image.jpg"', $output);
    }

    public function test_bulk_modifies_attributes_ending_in_src() {
        $tag = '<img lazy-src="a.jpg" data-src="b.jpg" alt="test">';
        $output = autoptimizeAttributeParser::modify_attributes($tag, 'img', '/src$/i', function($name, $value) {
            return $value . "?v=2";
        });

        $this->assertStringContainsString('lazy-src="a.jpg?v=2"', $output);
        $this->assertStringContainsString('data-src="b.jpg?v=2"', $output);
        $this->assertStringContainsString('alt="test"', $output);
    }

    public function test_removes_attribute() {
        $tag = '<div style="color:red;" class="keep">';
        $output = autoptimizeAttributeParser::remove($tag, 'div', 'style');

        $this->assertStringNotContainsString('style=', $output);
        $this->assertStringContainsString('class="keep"', $output);
    }

    public function test_removes_attributes() {
        $tag = '<div style="color:red;" id="test" class="keep">';
        $output = autoptimizeAttributeParser::remove($tag, 'div', ['style', 'id']);

        $this->assertStringNotContainsString('style=', $output);
        $this->assertStringNotContainsString('id=', $output);
        $this->assertStringContainsString('class="keep"', $output);
    }

    public function test_removes_attribute_via_callback_null() {
        $tag = '<div style="color:red;" class="keep">';
        $output = autoptimizeAttributeParser::modify_attribute($tag, 'div', 'style', function($name, $value) {
            return null;
        });

        $this->assertStringNotContainsString('style=', $output);
        $this->assertStringContainsString('class="keep"', $output);
    }

    public function test_quote_normalization() {
        $tag = "<img src='/single.jpg' />";
        $output = autoptimizeAttributeParser::replace($tag, 'img', 'src', 'double.jpg');
        $this->assertStringContainsString('src="double.jpg"', $output);
    }

    public function test_malformed_html_rebuild() {
        $tag = '<img title=noquotes alt=test>';
        $output = autoptimizeAttributeParser::rebuild($tag, 'img');
        $this->assertStringContainsString('title="noquotes"', $output);
        $this->assertStringContainsString('alt="test"', $output);
    }

    public function test_relative_path_preservation_on_modify() {
        $tag = '<img src="flowerpot.png">';
        $output = autoptimizeAttributeParser::modify($tag, 'img', [], ['alt' => 'test']);
        $this->assertStringContainsString('src="flowerpot.png"', $output);
    }

    public function test_relative_path_preservation_on_rebuild() {
        $tag = '<img src="flowerpot.png">';
        $output = autoptimizeAttributeParser::rebuild($tag, 'img');
        $this->assertStringContainsString('src="flowerpot.png"', $output);
    }

    public function test_allows_image_data_uris() {
        $data_uri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $tag = '<img src="' . $data_uri . '">';
        $output = autoptimizeAttributeParser::replace($tag, 'img', 'alt', 'pixel');
        $this->assertStringContainsString('src="' . $data_uri . '"', $output);
        $this->assertStringContainsString('alt="pixel"', $output);
    }

    public function test_disallow_data_html() {
        $data_uri = 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==';
        $tag = '<a href="' . $data_uri . '">';
        $output = autoptimizeAttributeParser::replace($tag, 'a', 'alt', 'pixel');
        $this->assertStringNotContainsString('src="data:text/html"', $output);
    }

    public function test_handles_multiline_tags_and_extra_whitespace() {
        $tag = "<img \n src=\"image.jpg\" \t alt=\"spaced\" \n>";
        $output = autoptimizeAttributeParser::replace($tag, 'img', 'alt', 'fixed');
        $this->assertStringContainsString('src="image.jpg"', $output);
        $this->assertStringContainsString('alt="fixed"', $output);
    }

    public function test_preserves_self_closing_slash() {
        $tag = '<img src="test.jpg" />';
        $output = autoptimizeAttributeParser::replace($tag, 'img', 'alt', 'check');
        $this->assertStringContainsString('/>', $output);
    }

    // --- Multi-element target tests ---

    public function test_modify_img_preserves_trailing_noscript() {
        $tag = '<img src="foo.png" alt="bar"><noscript>fallback content</noscript>';
        $output = autoptimizeAttributeParser::replace($tag, 'img', ['src' => 'lazy.png', 'loading' => 'lazy']);

        $this->assertStringContainsString('src="lazy.png"', $output);
        $this->assertStringContainsString('alt="bar"', $output);
        $this->assertStringContainsString('loading="lazy"', $output);
        $this->assertStringContainsString('<noscript>fallback content</noscript>', $output);
    }

    public function test_modify_img_when_preceded_by_other_tags() {
        $tag = '<noscript>fallback content</noscript><img src="foo.png" alt="bar">';
        $output = autoptimizeAttributeParser::replace($tag, 'img', ['src' => 'lazy.png']);

        $this->assertStringContainsString('src="lazy.png"', $output);
        $this->assertStringContainsString('<noscript>fallback content</noscript>', $output);
    }

    public function test_modify_iframe_preserves_closing_tag_and_inner_content() {
        $tag = '<iframe src="https://example.com" width="600" height="400">Your browser does not support iframes.</iframe>';
        $output = autoptimizeAttributeParser::replace($tag, 'iframe', [
            'src'     => 'about:blank',
            'data-src' => 'https://example.com',
            'loading' => 'lazy',
        ]);

        $this->assertStringContainsString('src="about:blank"', $output);
        $this->assertStringContainsString('data-src="https://example.com"', $output);
        $this->assertStringContainsString('loading="lazy"', $output);
        $this->assertStringContainsString('width="600"', $output);
        $this->assertStringContainsString('height="400"', $output);
        $this->assertStringContainsString('Your browser does not support iframes.', $output);
        $this->assertStringContainsString('</iframe>', $output);
    }

    public function test_modify_multiple_iframes_preserves_structure() {
        $tag = '<div class="embeds"><iframe src="https://youtube.com/embed/abc" frameborder="0"></iframe><p>Some text</p><iframe src="https://youtube.com/embed/xyz" frameborder="0"></iframe></div>';
        $output = autoptimizeAttributeParser::modify($tag, 'iframe', ['frameborder' => 'data-frameborder'], ['loading' => 'lazy']);

        // Both iframes should be modified
        $this->assertStringContainsString('data-frameborder="0"', $output);
        $this->assertStringContainsString('loading="lazy"', $output);
        $this->assertStringNotContainsString(' frameborder=', $output);
        // Surrounding structure preserved
        $this->assertStringContainsString('<div class="embeds">', $output);
        $this->assertStringContainsString('<p>Some text</p>', $output);
        $this->assertStringContainsString('</iframe>', $output);
        $this->assertStringContainsString('</div>', $output);
    }

    public function test_modify_img_inside_picture_leaves_other_tags() {
        $tag = '<picture><source srcset="large.webp" type="image/webp"><img src="fallback.png" alt="photo"></picture>';
        $output = autoptimizeAttributeParser::replace($tag, 'img', ['class' => 'lazyload']);

        $this->assertStringContainsString('class="lazyload"', $output);
        $this->assertStringContainsString('src="fallback.png"', $output);
        // source and picture tags untouched
        $this->assertStringContainsString('<picture>', $output);
        $this->assertStringContainsString('<source srcset="large.webp" type="image/webp">', $output);
        $this->assertStringContainsString('</picture>', $output);
    }

    public function test_rename_script_attributes_preserves_closing_tag() {
        $tag = '<script type="text/javascript" src="app.js"></script>';
        $output = autoptimizeAttributeParser::rename($tag, 'script', ['type' => 'data-type', 'src' => 'data-src']);

        $this->assertStringContainsString('data-type="text/javascript"', $output);
        $this->assertStringContainsString('data-src="app.js"', $output);
        $this->assertStringNotContainsString(' type=', $output);
        $this->assertStringNotContainsString(' src=', $output);
        $this->assertStringContainsString('</script>', $output);
    }

    public function test_target_only_modifies_matching_tags() {
        $tag = '<img src="hero.png" alt="hero"><img src="thumb.png" alt="thumb"><a href="/page">link</a>';
        $output = autoptimizeAttributeParser::replace($tag, 'img', ['loading' => 'lazy']);

        // Both imgs get loading=lazy
        $this->assertEquals(2, substr_count($output, 'loading="lazy"'));
        // Both imgs retain their src
        $this->assertStringContainsString('src="hero.png"', $output);
        $this->assertStringContainsString('src="thumb.png"', $output);
        // The <a> tag is untouched
        $this->assertStringContainsString('<a href="/page">link</a>', $output);
    }

    public function test_get_attribute_from_targeted_tag_in_multi_element_html() {
        $tag = '<picture><source srcset="large.webp"><img src="fallback.png" alt="photo"></picture>';
        $src = autoptimizeAttributeParser::get_attribute($tag, 'img', 'src');

        $this->assertEquals('fallback.png', $src);
    }

    public function test_get_attribute_skips_non_matching_tags() {
        $tag = '<div class="wrapper"><img src="test.png"></div>';
        $src = autoptimizeAttributeParser::get_attribute($tag, 'img', 'src');
        $cls = autoptimizeAttributeParser::get_attribute($tag, 'div', 'class');

        $this->assertEquals('test.png', $src);
        $this->assertEquals('wrapper', $cls);
    }

    public function test_get_attribute_returns_null_when_target_not_found() {
        $tag = '<div class="wrapper"><span>text</span></div>';
        $result = autoptimizeAttributeParser::get_attribute($tag, 'img', 'src');

        $this->assertNull($result);
    }

    public function test_iframe_remove_preserves_closing_tag() {
        $tag = '<iframe src="https://example.com" frameborder="0" scrolling="no">Fallback</iframe>';
        $output = autoptimizeAttributeParser::remove($tag, 'iframe', ['frameborder', 'scrolling']);

        $this->assertStringContainsString('src="https://example.com"', $output);
        $this->assertStringNotContainsString('frameborder', $output);
        $this->assertStringNotContainsString('scrolling', $output);
        $this->assertStringContainsString('Fallback', $output);
        $this->assertStringContainsString('</iframe>', $output);
    }
}
