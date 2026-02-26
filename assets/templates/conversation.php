<?php
get_header();
$messages = get_post_meta(get_the_ID(), '_full_payload', true);
$source_url = get_post_meta(get_the_ID(), '_source_url', true);
$source = get_post_meta(get_the_ID(), '_source', true);
$source_class = in_array(strtolower($source), ['perplexity', 'chatgpt']) ? strtolower($source) : 'generic';
?>

<div class="mp-conversation-wrap">
    <header class="mp-chat-header">
        <?php if ($source) : ?>
            <span class="mp-source-badge badge-<?php echo esc_attr($source_class); ?>">
                Generated via <?php echo esc_html(ucfirst($source)); ?>
            </span>
        <?php endif; ?>
        <h1><?php the_title(); ?></h1>
    </header>

    <div class="mp-chat-log">
        <?php if ($messages) : foreach ($messages as $msg) : ?>
            <div class="mp-chat-item">
                <div class="mp-chat-question">
                    <?php echo esc_html($msg['question']); ?>
                </div>
                <div class="mp-chat-answer">
                    <?php echo wp_kses_post($msg['answerHtml']); ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($source_url) : ?>
        <a href="<?php echo esc_url($source_url); ?>" class="mp-source-btn" target="_blank">
            View on <?php echo esc_html(ucfirst($source ?: 'Source')); ?>
        </a>
    <?php endif; ?>
</div>

<?php get_footer(); ?>