WITH counts AS (
    SELECT channel, status, count(*) AS notifications, sum(attempts) AS attempts, count(provider_message_id) AS provider_ids
    FROM notifications GROUP BY channel, status ORDER BY channel, status
),
totals AS (
    SELECT count(*) AS total, count(DISTINCT recipient) AS distinct_recipients,
        count(DISTINCT provider_message_id) AS distinct_provider_ids,
        count(*) FILTER (WHERE finished_at IS NULL) AS unfinished,
        count(*) FILTER (WHERE status = 'accepted' AND provider_message_id IS DISTINCT FROM
            ('8a71a5ed-148a-4630-831c-' || lpad(to_hex(split_part(content,' ',4)::integer), 12, '0'))) AS mismatched_provider_ids
    FROM notifications
)
SELECT to_jsonb(totals) || jsonb_build_object(
    'by_status', (SELECT jsonb_agg(to_jsonb(counts)) FROM counts),
    'queue_jobs', (SELECT count(*) FROM jobs),
    'failed_jobs', (SELECT count(*) FROM failed_jobs),
    'batch_count', (SELECT count(*) FROM notification_batches),
    'batch_items', (SELECT sum(count) FROM notification_batches),
    'idempotency_records', (SELECT count(*) FROM idempotency_records)
) FROM totals;
