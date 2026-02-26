<?php get_header(); ?>
<div class="mp-conversation-wrap mp-conversation-hub">
    <h1>Conversations</h1>
    <?php if (have_posts()) : while (have_posts()) : the_post();
        $source = get_post_meta(get_the_ID(), '_source', true);
        $source_class = in_array(strtolower($source), ['perplexity', 'chatgpt']) ? strtolower($source) : 'generic';
    ?>
        <a href="<?php the_permalink(); ?>" class="mp-chat-card">
            <?php if ($source) : ?>
                <span class="mp-source-badge badge-<?php echo esc_attr($source_class); ?>">
                    <?php echo esc_html(ucfirst($source)); ?>
                </span>
            <?php endif; ?>

            <h3><?php the_title(); ?></h3>
            <div class="mp-chat-meta">
                <span>📅 <?php echo get_the_date(); ?></span>
            </div>
        </a>
    <?php endwhile; else : ?>
        <p>No conversations found.</p>
    <?php endif; ?>
</div>
<?php get_footer(); ?>