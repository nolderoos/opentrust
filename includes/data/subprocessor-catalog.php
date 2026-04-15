<?php
/**
 * Subprocessor catalog.
 *
 * Curated list of common SaaS vendors with factual information that can be
 * auto-filled into the ot_subprocessor meta box from the admin typeahead.
 *
 * Schema per entry:
 *   'slug' => [
 *       'name'          => 'Canonical Name',
 *       'aliases'       => ['alt', 'nickname'],
 *       'fields'        => [ '_ot_sub_*' => string ],  // verified facts
 *       'fields_review' => [ '_ot_sub_*' => string ],  // templates to verify
 *   ]
 *
 * Rules followed by this catalog:
 *   1. `_ot_sub_country` is included only when the HQ / processing region is
 *      unambiguous. Multi-region cloud infra (AWS, GCP, Azure, Cloudflare,
 *      Fastly, Vercel, etc.) deliberately omits the key so the customer picks.
 *   2. `_ot_sub_data_processed` is always a template in `fields_review` so
 *      the UI marks it for user verification before publishing. The text is
 *      a reasonable starting point only.
 *   3. `_ot_sub_dpa_signed` is never touched because that is contract state.
 *
 * Extend without forking via the `opentrust_subprocessor_catalog` filter.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [

	'aws' => [
		'name'    => 'Amazon Web Services',
		'aliases' => [ 'aws', 'amazon web services', 'amazon aws' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Cloud infrastructure, compute, storage, and managed services.',
			'_ot_sub_website' => 'https://aws.amazon.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application data, user-generated content, system logs, and backups stored across compute, storage, and database services.',
		],
	],

	'gcp' => [
		'name'    => 'Google Cloud Platform',
		'aliases' => [ 'gcp', 'google cloud', 'google cloud platform' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Cloud infrastructure, compute, storage, and managed services.',
			'_ot_sub_website' => 'https://cloud.google.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application data, user-generated content, system logs, and backups stored across compute, storage, and database services.',
		],
	],

	'azure' => [
		'name'    => 'Microsoft Azure',
		'aliases' => [ 'azure', 'microsoft azure', 'ms azure' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Cloud infrastructure, compute, storage, and managed services.',
			'_ot_sub_website' => 'https://azure.microsoft.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application data, user-generated content, system logs, and backups stored across compute, storage, and database services.',
		],
	],

	'cloudflare' => [
		'name'    => 'Cloudflare',
		'aliases' => [ 'cloudflare', 'cf' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Content delivery network, DDoS protection, DNS, and edge compute.',
			'_ot_sub_website' => 'https://cloudflare.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'HTTP request metadata, IP addresses, request headers, and cached content served to end users.',
		],
	],

	'vercel' => [
		'name'    => 'Vercel',
		'aliases' => [ 'vercel', 'zeit', 'zeit now' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Frontend hosting, serverless functions, and edge deployment platform.',
			'_ot_sub_website' => 'https://vercel.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application source code, build artifacts, request logs, and runtime data for deployed sites and functions.',
		],
	],

	'netlify' => [
		'name'    => 'Netlify',
		'aliases' => [ 'netlify' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Frontend hosting, serverless functions, and continuous deployment platform.',
			'_ot_sub_website' => 'https://netlify.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application source code, build artifacts, request logs, and runtime data for deployed sites and functions.',
		],
	],

	'fly-io' => [
		'name'    => 'Fly.io',
		'aliases' => [ 'fly', 'fly.io', 'flyio' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Application hosting platform running containers on a global edge network.',
			'_ot_sub_website' => 'https://fly.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application containers, runtime data, logs, and persistent volumes for deployed services.',
		],
	],

	'railway' => [
		'name'    => 'Railway',
		'aliases' => [ 'railway', 'railway.app' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Application hosting and deployment platform for containers and databases.',
			'_ot_sub_website' => 'https://railway.app',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application source, runtime data, environment variables, and database contents for deployed services.',
		],
	],

	'render' => [
		'name'    => 'Render',
		'aliases' => [ 'render', 'render.com' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Application hosting platform for web services, databases, and background workers.',
			'_ot_sub_website' => 'https://render.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application source, runtime data, logs, and database contents for deployed services.',
		],
	],

	'digitalocean' => [
		'name'    => 'DigitalOcean',
		'aliases' => [ 'digitalocean', 'digital ocean', 'do' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Cloud infrastructure provider offering virtual servers, managed databases, and object storage.',
			'_ot_sub_website' => 'https://digitalocean.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application data, user-generated content, system logs, and backups stored on compute and storage services.',
		],
	],

	'linode' => [
		'name'    => 'Linode',
		'aliases' => [ 'linode', 'akamai linode' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Cloud infrastructure provider offering virtual servers and managed services.',
			'_ot_sub_website' => 'https://linode.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application data, user-generated content, system logs, and backups stored on compute and storage services.',
		],
	],

	'hetzner' => [
		'name'    => 'Hetzner',
		'aliases' => [ 'hetzner', 'hetzner online', 'hetzner cloud' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Cloud and dedicated server hosting provider.',
			'_ot_sub_country' => 'DE',
			'_ot_sub_website' => 'https://hetzner.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application data, user-generated content, system logs, and backups stored on compute and storage services.',
		],
	],

	'fastly' => [
		'name'    => 'Fastly',
		'aliases' => [ 'fastly' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Content delivery network and edge compute platform.',
			'_ot_sub_website' => 'https://fastly.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'HTTP request metadata, IP addresses, request headers, and cached content served to end users.',
		],
	],

	'datadog' => [
		'name'    => 'Datadog',
		'aliases' => [ 'datadog', 'data dog' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Infrastructure monitoring, application performance monitoring, and log management.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://datadoghq.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'System metrics, application logs, traces, and error events including user identifiers contained in telemetry.',
		],
	],

	'new-relic' => [
		'name'    => 'New Relic',
		'aliases' => [ 'new relic', 'newrelic' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Application performance monitoring and observability platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://newrelic.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'System metrics, application logs, traces, and error events including user identifiers contained in telemetry.',
		],
	],

	'sentry' => [
		'name'    => 'Sentry',
		'aliases' => [ 'sentry', 'sentry.io', 'getsentry' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Error tracking and application performance monitoring.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://sentry.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Error stack traces, breadcrumbs, request context, user identifiers, and device metadata captured at error time.',
		],
	],

	'logrocket' => [
		'name'    => 'LogRocket',
		'aliases' => [ 'logrocket', 'log rocket' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Session replay and frontend monitoring for web applications.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://logrocket.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Recorded user sessions, DOM events, console logs, network requests, and user identifiers from end-user browsers.',
		],
	],

	'fullstory' => [
		'name'    => 'FullStory',
		'aliases' => [ 'fullstory', 'full story' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Digital experience analytics and session replay for web and mobile.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://fullstory.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Recorded user sessions, click and scroll events, page content, and user identifiers from end-user devices.',
		],
	],

	'honeycomb' => [
		'name'    => 'Honeycomb',
		'aliases' => [ 'honeycomb', 'honeycomb.io' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Observability platform for distributed tracing and event-based debugging.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://honeycomb.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application traces, spans, events, and telemetry including user identifiers contained in instrumentation.',
		],
	],

	'grafana-cloud' => [
		'name'    => 'Grafana Cloud',
		'aliases' => [ 'grafana', 'grafana cloud', 'grafana labs' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Hosted observability platform for metrics, logs, traces, and dashboards.',
			'_ot_sub_website' => 'https://grafana.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'System metrics, application logs, traces, and dashboard data including user identifiers contained in telemetry.',
		],
	],

	'better-stack' => [
		'name'    => 'Better Stack',
		'aliases' => [ 'better stack', 'betterstack', 'better uptime', 'logtail' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Log management, uptime monitoring, and incident management platform.',
			'_ot_sub_website' => 'https://betterstack.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application logs, uptime check results, and incident metadata including user identifiers contained in log content.',
		],
	],

	'axiom' => [
		'name'    => 'Axiom',
		'aliases' => [ 'axiom', 'axiom.co' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Cloud-native log management and event data platform.',
			'_ot_sub_website' => 'https://axiom.co',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application logs and event data including user identifiers contained in log content.',
		],
	],

	'baselime' => [
		'name'    => 'Baselime',
		'aliases' => [ 'baselime' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Observability platform for serverless and cloud-native applications.',
			'_ot_sub_website' => 'https://baselime.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application logs, traces, and telemetry including user identifiers contained in instrumentation.',
		],
	],

	'postmark' => [
		'name'    => 'Postmark',
		'aliases' => [ 'postmark', 'postmarkapp' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Transactional email delivery service.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://postmarkapp.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Recipient email addresses, message content, delivery metadata, and bounce or complaint events.',
		],
	],

	'sendgrid' => [
		'name'    => 'SendGrid',
		'aliases' => [ 'sendgrid', 'twilio sendgrid' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Transactional and marketing email delivery service.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://sendgrid.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Recipient email addresses, message content, delivery metadata, and engagement events.',
		],
	],

	'resend' => [
		'name'    => 'Resend',
		'aliases' => [ 'resend', 'resend.com' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Transactional email delivery service for developers.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://resend.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Recipient email addresses, message content, delivery metadata, and bounce or complaint events.',
		],
	],

	'mailgun' => [
		'name'    => 'Mailgun',
		'aliases' => [ 'mailgun', 'sinch mailgun' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Transactional email delivery and validation service.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://mailgun.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Recipient email addresses, message content, delivery metadata, and engagement events.',
		],
	],

	'ses' => [
		'name'    => 'Amazon SES',
		'aliases' => [ 'ses', 'amazon ses', 'aws ses', 'simple email service' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Transactional email delivery service from Amazon Web Services.',
			'_ot_sub_website' => 'https://aws.amazon.com/ses',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Recipient email addresses, message content, delivery metadata, and bounce or complaint events.',
		],
	],

	'loops' => [
		'name'    => 'Loops',
		'aliases' => [ 'loops', 'loops.so' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Transactional and marketing email platform for SaaS companies.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://loops.so',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Contact email addresses, names, user properties, message content, and engagement events.',
		],
	],

	'brevo' => [
		'name'    => 'Brevo',
		'aliases' => [ 'brevo', 'sendinblue', 'send in blue' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Transactional and marketing email, SMS, and customer messaging platform.',
			'_ot_sub_country' => 'FR',
			'_ot_sub_website' => 'https://brevo.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Contact email addresses, phone numbers, names, message content, and engagement events.',
		],
	],

	'mailchimp' => [
		'name'    => 'Mailchimp',
		'aliases' => [ 'mailchimp', 'mail chimp', 'intuit mailchimp' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Marketing email and audience management platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://mailchimp.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Subscriber email addresses, names, audience segmentation data, message content, and engagement events.',
		],
	],

	'kit' => [
		'name'    => 'Kit',
		'aliases' => [ 'convertkit', 'convert kit', 'kit' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Email marketing platform for creators and publishers.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://kit.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Subscriber email addresses, names, tags, message content, and engagement events.',
		],
	],

	'customer-io' => [
		'name'    => 'Customer.io',
		'aliases' => [ 'customer.io', 'customerio', 'customer io' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Customer messaging platform for email, SMS, and push notifications.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://customer.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer profiles, email addresses, behavioral events, message content, and engagement data.',
		],
	],

	'klaviyo' => [
		'name'    => 'Klaviyo',
		'aliases' => [ 'klaviyo' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Marketing automation and customer data platform for email and SMS.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://klaviyo.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer profiles, email addresses, phone numbers, purchase history, and engagement events.',
		],
	],

	'stripe' => [
		'name'    => 'Stripe',
		'aliases' => [ 'stripe' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Payment processing and billing infrastructure.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://stripe.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Payment card details (tokenized), billing address, customer email, and transaction metadata.',
		],
	],

	'paddle' => [
		'name'    => 'Paddle',
		'aliases' => [ 'paddle', 'paddle.com' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Merchant of record payment and subscription platform for software companies.',
			'_ot_sub_country' => 'GB',
			'_ot_sub_website' => 'https://paddle.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Payment card details (tokenized), billing address, customer email, tax information, and transaction metadata.',
		],
	],

	'lemon-squeezy' => [
		'name'    => 'Lemon Squeezy',
		'aliases' => [ 'lemon squeezy', 'lemonsqueezy' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Merchant of record payment and subscription platform for digital products.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://lemonsqueezy.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Payment card details (tokenized), billing address, customer email, tax information, and transaction metadata.',
		],
	],

	'braintree' => [
		'name'    => 'Braintree',
		'aliases' => [ 'braintree', 'paypal braintree' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Payment processing platform owned by PayPal.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://braintreepayments.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Payment card details (tokenized), billing address, customer email, and transaction metadata.',
		],
	],

	'paypal' => [
		'name'    => 'PayPal',
		'aliases' => [ 'paypal', 'pay pal' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Online payment processing and digital wallet service.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://paypal.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Payer account identifiers, email addresses, billing address, and transaction metadata.',
		],
	],

	'chargebee' => [
		'name'    => 'Chargebee',
		'aliases' => [ 'chargebee' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Subscription billing and revenue management platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://chargebee.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer profiles, billing address, email, subscription data, and transaction metadata.',
		],
	],

	'recurly' => [
		'name'    => 'Recurly',
		'aliases' => [ 'recurly' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Subscription billing and revenue management platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://recurly.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer profiles, billing address, email, subscription data, and transaction metadata.',
		],
	],

	'google-analytics' => [
		'name'    => 'Google Analytics',
		'aliases' => [ 'google analytics', 'ga', 'ga4', 'universal analytics' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Web and app analytics platform.',
			'_ot_sub_website' => 'https://analytics.google.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Page views, user interactions, device and browser metadata, IP addresses, and pseudonymous user identifiers.',
		],
	],

	'posthog' => [
		'name'    => 'PostHog',
		'aliases' => [ 'posthog', 'post hog' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Product analytics, session replay, and feature flag platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://posthog.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Product usage events, user identifiers, session recordings, and device metadata.',
		],
	],

	'plausible' => [
		'name'    => 'Plausible Analytics',
		'aliases' => [ 'plausible', 'plausible analytics' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Privacy-focused web analytics platform.',
			'_ot_sub_country' => 'EE',
			'_ot_sub_website' => 'https://plausible.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Aggregated page views, referrers, and anonymized device and browser metadata.',
		],
	],

	'fathom' => [
		'name'    => 'Fathom Analytics',
		'aliases' => [ 'fathom', 'fathom analytics' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Privacy-focused web analytics platform.',
			'_ot_sub_website' => 'https://usefathom.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Aggregated page views, referrers, and anonymized device and browser metadata.',
		],
	],

	'mixpanel' => [
		'name'    => 'Mixpanel',
		'aliases' => [ 'mixpanel' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Product analytics platform for tracking user behavior.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://mixpanel.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Product usage events, user identifiers, user properties, and device metadata.',
		],
	],

	'amplitude' => [
		'name'    => 'Amplitude',
		'aliases' => [ 'amplitude' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Product analytics platform for tracking user behavior.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://amplitude.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Product usage events, user identifiers, user properties, and device metadata.',
		],
	],

	'heap' => [
		'name'    => 'Heap',
		'aliases' => [ 'heap', 'heap analytics' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Product analytics platform with automatic event capture.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://heap.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Product usage events, user identifiers, user properties, and device metadata.',
		],
	],

	'segment' => [
		'name'    => 'Segment',
		'aliases' => [ 'segment', 'twilio segment' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Customer data platform for collecting and routing analytics events.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://segment.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer profiles, user identifiers, event data, and traits forwarded to downstream tools.',
		],
	],

	'rudderstack' => [
		'name'    => 'RudderStack',
		'aliases' => [ 'rudderstack', 'rudder stack' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Customer data platform for collecting and routing analytics events.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://rudderstack.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer profiles, user identifiers, event data, and traits forwarded to downstream tools.',
		],
	],

	'intercom' => [
		'name'    => 'Intercom',
		'aliases' => [ 'intercom' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Customer messaging and support platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://intercom.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer contact details, conversation history, user attributes, and product usage events.',
		],
	],

	'zendesk' => [
		'name'    => 'Zendesk',
		'aliases' => [ 'zendesk' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Customer support ticketing and help desk platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://zendesk.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer contact details, support ticket content, conversation history, and attachments.',
		],
	],

	'hubspot' => [
		'name'    => 'HubSpot',
		'aliases' => [ 'hubspot', 'hub spot' ],
		'fields'  => [
			'_ot_sub_purpose' => 'CRM, marketing, sales, and customer service platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://hubspot.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Contact details, company records, email correspondence, marketing engagement, and sales pipeline data.',
		],
	],

	'front' => [
		'name'    => 'Front',
		'aliases' => [ 'front', 'frontapp' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Shared inbox and customer communication platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://front.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer contact details, email and message content, conversation history, and attachments.',
		],
	],

	'help-scout' => [
		'name'    => 'Help Scout',
		'aliases' => [ 'help scout', 'helpscout' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Customer support help desk and shared inbox platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://helpscout.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer contact details, support ticket content, conversation history, and attachments.',
		],
	],

	'crisp' => [
		'name'    => 'Crisp',
		'aliases' => [ 'crisp', 'crisp chat' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Customer messaging and live chat platform.',
			'_ot_sub_country' => 'FR',
			'_ot_sub_website' => 'https://crisp.chat',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer contact details, chat transcripts, user attributes, and conversation history.',
		],
	],

	'freshdesk' => [
		'name'    => 'Freshdesk',
		'aliases' => [ 'freshdesk', 'fresh desk', 'freshworks' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Customer support ticketing and help desk platform.',
			'_ot_sub_website' => 'https://freshworks.com/freshdesk',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer contact details, support ticket content, conversation history, and attachments.',
		],
	],

	'pipedrive' => [
		'name'    => 'Pipedrive',
		'aliases' => [ 'pipedrive' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Sales CRM and pipeline management platform.',
			'_ot_sub_country' => 'EE',
			'_ot_sub_website' => 'https://pipedrive.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Contact details, company records, deal pipeline data, and email correspondence.',
		],
	],

	'salesforce' => [
		'name'    => 'Salesforce',
		'aliases' => [ 'salesforce', 'sfdc' ],
		'fields'  => [
			'_ot_sub_purpose' => 'CRM and enterprise cloud platform for sales, service, and marketing.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://salesforce.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Contact details, company records, sales pipeline data, customer interactions, and case history.',
		],
	],

	'auth0' => [
		'name'    => 'Auth0',
		'aliases' => [ 'auth0', 'okta auth0' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Identity and access management platform for authentication.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://auth0.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User credentials, email addresses, profile attributes, session tokens, and authentication logs.',
		],
	],

	'clerk' => [
		'name'    => 'Clerk',
		'aliases' => [ 'clerk', 'clerk.dev', 'clerk.com' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Authentication and user management platform for web and mobile apps.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://clerk.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User credentials, email addresses, phone numbers, profile attributes, and authentication logs.',
		],
	],

	'workos' => [
		'name'    => 'WorkOS',
		'aliases' => [ 'workos', 'work os' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Enterprise authentication, SSO, and directory sync platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://workos.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User credentials, email addresses, profile attributes, directory records, and authentication logs.',
		],
	],

	'stytch' => [
		'name'    => 'Stytch',
		'aliases' => [ 'stytch' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Passwordless authentication and user management platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://stytch.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User credentials, email addresses, phone numbers, profile attributes, and authentication logs.',
		],
	],

	'descope' => [
		'name'    => 'Descope',
		'aliases' => [ 'descope' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Authentication and user management platform with visual flow builder.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://descope.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User credentials, email addresses, phone numbers, profile attributes, and authentication logs.',
		],
	],

	'firebase-auth' => [
		'name'    => 'Firebase Authentication',
		'aliases' => [ 'firebase auth', 'firebase authentication' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Authentication service from Google Firebase.',
			'_ot_sub_website' => 'https://firebase.google.com/products/auth',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User credentials, email addresses, phone numbers, profile attributes, and authentication logs.',
		],
	],

	'supabase' => [
		'name'    => 'Supabase',
		'aliases' => [ 'supabase' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed Postgres database, authentication, storage, and backend platform.',
			'_ot_sub_website' => 'https://supabase.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application database contents, user credentials, uploaded files, and authentication logs.',
		],
	],

	'firebase' => [
		'name'    => 'Firebase',
		'aliases' => [ 'firebase', 'google firebase' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Google backend platform offering database, authentication, hosting, and analytics.',
			'_ot_sub_website' => 'https://firebase.google.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application database contents, user credentials, uploaded files, and analytics events.',
		],
	],

	'mongodb-atlas' => [
		'name'    => 'MongoDB Atlas',
		'aliases' => [ 'mongodb atlas', 'mongo atlas', 'atlas' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed MongoDB database service.',
			'_ot_sub_website' => 'https://mongodb.com/atlas',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application database contents, query logs, and backups.',
		],
	],

	'planetscale' => [
		'name'    => 'PlanetScale',
		'aliases' => [ 'planetscale', 'planet scale' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed MySQL database platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://planetscale.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application database contents, query logs, and backups.',
		],
	],

	'neon' => [
		'name'    => 'Neon',
		'aliases' => [ 'neon', 'neon.tech', 'neon database' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed serverless Postgres database platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://neon.tech',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application database contents, query logs, and backups.',
		],
	],

	'upstash' => [
		'name'    => 'Upstash',
		'aliases' => [ 'upstash' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed serverless Redis, Kafka, and vector database platform.',
			'_ot_sub_website' => 'https://upstash.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application cache data, queue messages, and database contents.',
		],
	],

	'turso' => [
		'name'    => 'Turso',
		'aliases' => [ 'turso' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed SQLite-compatible edge database platform.',
			'_ot_sub_website' => 'https://turso.tech',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application database contents, query logs, and backups.',
		],
	],

	'openai' => [
		'name'    => 'OpenAI',
		'aliases' => [ 'openai', 'open ai', 'chatgpt api' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Large language model API provider.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://openai.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Prompt content, completions, and API request metadata submitted to model endpoints.',
		],
	],

	'anthropic' => [
		'name'    => 'Anthropic',
		'aliases' => [ 'anthropic', 'claude' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Large language model API provider, maker of Claude.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://anthropic.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Prompt content, completions, and API request metadata submitted to model endpoints.',
		],
	],

	'google-ai' => [
		'name'    => 'Google AI',
		'aliases' => [ 'google ai', 'gemini', 'gemini api', 'google generative ai' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Large language model API provider for Gemini models.',
			'_ot_sub_website' => 'https://ai.google.dev',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Prompt content, completions, and API request metadata submitted to model endpoints.',
		],
	],

	'mistral' => [
		'name'    => 'Mistral AI',
		'aliases' => [ 'mistral', 'mistral ai' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Large language model API provider.',
			'_ot_sub_country' => 'FR',
			'_ot_sub_website' => 'https://mistral.ai',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Prompt content, completions, and API request metadata submitted to model endpoints.',
		],
	],

	'perplexity' => [
		'name'    => 'Perplexity',
		'aliases' => [ 'perplexity', 'perplexity ai' ],
		'fields'  => [
			'_ot_sub_purpose' => 'AI-powered search and language model API provider.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://perplexity.ai',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Prompt content, search queries, completions, and API request metadata.',
		],
	],

	'openrouter' => [
		'name'    => 'OpenRouter',
		'aliases' => [ 'openrouter', 'open router' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Unified API gateway for accessing multiple large language model providers.',
			'_ot_sub_website' => 'https://openrouter.ai',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Prompt content, completions, and API request metadata routed to upstream model providers.',
		],
	],

	'replicate' => [
		'name'    => 'Replicate',
		'aliases' => [ 'replicate', 'replicate.com' ],
		'fields'  => [
			'_ot_sub_purpose' => 'API platform for running open-source machine learning models.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://replicate.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Model inputs (including images, audio, and text), outputs, and API request metadata.',
		],
	],

	'slack' => [
		'name'    => 'Slack',
		'aliases' => [ 'slack' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Team messaging and collaboration platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://slack.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User profiles, message content, channel membership, and uploaded files.',
		],
	],

	'linear' => [
		'name'    => 'Linear',
		'aliases' => [ 'linear', 'linear.app' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Issue tracking and project management tool for software teams.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://linear.app',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User profiles, issue content, project data, and comments.',
		],
	],

	'notion' => [
		'name'    => 'Notion',
		'aliases' => [ 'notion', 'notion.so' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Collaborative workspace for notes, documents, and databases.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://notion.so',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User profiles, document content, database records, and uploaded files.',
		],
	],

	'asana' => [
		'name'    => 'Asana',
		'aliases' => [ 'asana' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Work and project management platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://asana.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User profiles, task content, project data, and comments.',
		],
	],

	'trello' => [
		'name'    => 'Trello',
		'aliases' => [ 'trello', 'atlassian trello' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Kanban-style project management tool.',
			'_ot_sub_website' => 'https://trello.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User profiles, card content, board data, and attachments.',
		],
	],

	'zoom' => [
		'name'    => 'Zoom',
		'aliases' => [ 'zoom', 'zoom video' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Video conferencing and communications platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://zoom.us',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User profiles, meeting metadata, call recordings, and chat transcripts.',
		],
	],

	'google-workspace' => [
		'name'    => 'Google Workspace',
		'aliases' => [ 'google workspace', 'gsuite', 'g suite' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Productivity suite including email, documents, calendar, and storage.',
			'_ot_sub_website' => 'https://workspace.google.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Employee email, documents, calendar events, contacts, and files stored in Drive.',
		],
	],

	'microsoft-365' => [
		'name'    => 'Microsoft 365',
		'aliases' => [ 'microsoft 365', 'm365', 'office 365', 'o365' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Productivity suite including email, documents, calendar, and storage.',
			'_ot_sub_website' => 'https://microsoft.com/microsoft-365',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Employee email, documents, calendar events, contacts, and files stored in OneDrive or SharePoint.',
		],
	],

	'github' => [
		'name'    => 'GitHub',
		'aliases' => [ 'github', 'git hub' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Source code hosting and collaboration platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://github.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Source code, issues, pull requests, user profiles, and CI artifacts.',
		],
	],

	'gitlab' => [
		'name'    => 'GitLab',
		'aliases' => [ 'gitlab', 'git lab' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Source code hosting, CI/CD, and DevOps platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://about.gitlab.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Source code, issues, merge requests, user profiles, and CI artifacts.',
		],
	],

	'bitbucket' => [
		'name'    => 'Bitbucket',
		'aliases' => [ 'bitbucket', 'atlassian bitbucket' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Source code hosting and collaboration platform from Atlassian.',
			'_ot_sub_website' => 'https://bitbucket.org',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Source code, issues, pull requests, user profiles, and CI artifacts.',
		],
	],

	'circleci' => [
		'name'    => 'CircleCI',
		'aliases' => [ 'circleci', 'circle ci' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Continuous integration and delivery platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://circleci.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Source code, build logs, environment variables, and CI artifacts.',
		],
	],

	'buildkite' => [
		'name'    => 'Buildkite',
		'aliases' => [ 'buildkite', 'build kite' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Continuous integration and delivery platform with self-hosted agents.',
			'_ot_sub_country' => 'AU',
			'_ot_sub_website' => 'https://buildkite.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Build metadata, pipeline configuration, logs, and job artifacts.',
		],
	],

	'onesignal' => [
		'name'    => 'OneSignal',
		'aliases' => [ 'onesignal', 'one signal' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Push notification and customer messaging platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://onesignal.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Device tokens, user identifiers, subscription preferences, and notification content.',
		],
	],

	'knock' => [
		'name'    => 'Knock',
		'aliases' => [ 'knock', 'knock.app' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Notification infrastructure for product messaging across channels.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://knock.app',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User profiles, notification content, delivery metadata, and channel preferences.',
		],
	],

	'courier' => [
		'name'    => 'Courier',
		'aliases' => [ 'courier', 'courier.com' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Notification infrastructure for product messaging across channels.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://courier.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User profiles, notification content, delivery metadata, and channel preferences.',
		],
	],

	'cloudinary' => [
		'name'    => 'Cloudinary',
		'aliases' => [ 'cloudinary' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Media management, image and video optimization, and delivery platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://cloudinary.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Uploaded images, videos, and associated metadata.',
		],
	],

	'imgix' => [
		'name'    => 'imgix',
		'aliases' => [ 'imgix' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Real-time image processing and delivery platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://imgix.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Source images and associated metadata processed for delivery.',
		],
	],

	'uploadcare' => [
		'name'    => 'Uploadcare',
		'aliases' => [ 'uploadcare', 'upload care' ],
		'fields'  => [
			'_ot_sub_purpose' => 'File upload, storage, and media processing platform.',
			'_ot_sub_website' => 'https://uploadcare.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Uploaded files, images, videos, and associated metadata.',
		],
	],

	'bunny-net' => [
		'name'    => 'Bunny.net',
		'aliases' => [ 'bunny', 'bunny.net', 'bunnycdn', 'bunny cdn' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Content delivery network and edge storage platform.',
			'_ot_sub_country' => 'SI',
			'_ot_sub_website' => 'https://bunny.net',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'HTTP request metadata, IP addresses, and cached content served to end users.',
		],
	],

	'twilio' => [
		'name'    => 'Twilio',
		'aliases' => [ 'twilio' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Communications API platform for SMS, voice, and messaging.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://twilio.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Phone numbers, message content, call metadata, and delivery events.',
		],
	],

	'algolia' => [
		'name'    => 'Algolia',
		'aliases' => [ 'algolia' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Hosted search and discovery API platform.',
			'_ot_sub_country' => 'FR',
			'_ot_sub_website' => 'https://algolia.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Indexed content, search queries, and analytics events from end users.',
		],
	],

	'typesense' => [
		'name'    => 'Typesense',
		'aliases' => [ 'typesense', 'typesense cloud' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Open-source search engine with managed cloud hosting.',
			'_ot_sub_website' => 'https://typesense.org',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Indexed content and search queries from end users.',
		],
	],

	'meilisearch' => [
		'name'    => 'Meilisearch',
		'aliases' => [ 'meilisearch', 'meili search', 'meili' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Open-source search engine with managed cloud hosting.',
			'_ot_sub_country' => 'FR',
			'_ot_sub_website' => 'https://meilisearch.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Indexed content and search queries from end users.',
		],
	],

	'figma' => [
		'name'    => 'Figma',
		'aliases' => [ 'figma' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Collaborative interface design and prototyping platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://figma.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User profiles, design files, comments, and collaboration metadata.',
		],
	],

	'canva' => [
		'name'    => 'Canva',
		'aliases' => [ 'canva' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Online graphic design and publishing platform.',
			'_ot_sub_country' => 'AU',
			'_ot_sub_website' => 'https://canva.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User profiles, design files, uploaded assets, and collaboration metadata.',
		],
	],

	'dropbox' => [
		'name'    => 'Dropbox',
		'aliases' => [ 'dropbox', 'drop box' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Cloud file storage and sharing platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://dropbox.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Uploaded files, folder metadata, sharing links, and user profiles.',
		],
	],

	'box' => [
		'name'    => 'Box',
		'aliases' => [ 'box', 'box.com' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Cloud content management and file sharing platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://box.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Uploaded files, folder metadata, sharing links, and user profiles.',
		],
	],

	'automattic' => [
		'name'    => 'Automattic',
		'aliases' => [ 'automattic', 'automattic inc' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Parent company operating WordPress.com, Jetpack, Akismet, WooCommerce.com, and Tumblr.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://automattic.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Site content, visitor metadata, comments, backups, and account information.',
		],
	],

	'jetpack' => [
		'name'    => 'Jetpack',
		'aliases' => [ 'jetpack', 'jetpack.com' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Security, performance, backups, site stats, and content delivery for WordPress sites.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://jetpack.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Site content, visitor metadata, comments, backup archives, and usage analytics from the connected WordPress site.',
		],
	],

	'akismet' => [
		'name'    => 'Akismet',
		'aliases' => [ 'akismet', 'akismet anti-spam' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Spam detection for comments and form submissions on WordPress sites.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://akismet.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Comment content, author names, email addresses, IP addresses, and user agent strings submitted for spam classification.',
		],
	],

	'wordpress-com' => [
		'name'    => 'WordPress.com',
		'aliases' => [ 'wordpress.com', 'wordpress com', 'wp.com', 'wpcom' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed WordPress hosting, publishing, and site management service operated by Automattic.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://wordpress.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Site content, media uploads, visitor analytics, comments, and author account information.',
		],
	],

	'meta' => [
		'name'    => 'Meta',
		'aliases' => [ 'meta', 'meta platforms', 'facebook', 'instagram', 'whatsapp', 'facebook business' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Social platform, advertising, and business tools from Meta, covering Facebook, Instagram, and WhatsApp.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://about.meta.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Advertising events, custom audience data, Meta Pixel and Conversions API events, and authentication data when Facebook Login is used.',
		],
	],

	'mosa-cloud' => [
		'name'    => 'MOSA Cloud',
		'aliases' => [ 'mosa', 'mosa cloud', 'mosa.cloud', 'mosacloud' ],
		'fields'  => [
			'_ot_sub_purpose' => 'EU-based productivity suite with documents, file storage, email, and video meetings, built on open-source infrastructure.',
			'_ot_sub_country' => 'NL',
			'_ot_sub_website' => 'https://mosa.cloud',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Documents, spreadsheets, uploaded files, email messages, meeting recordings and transcripts, and team communications.',
		],
	],

	// WordPress hosting ecosystem

	'wp-engine' => [
		'name'    => 'WP Engine',
		'aliases' => [ 'wpengine', 'wp engine' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed WordPress hosting and site infrastructure.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://wpengine.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Website files, database contents, visitor IP addresses, server logs, and account billing information.',
		],
	],

	'kinsta' => [
		'name'    => 'Kinsta',
		'aliases' => [ 'kinsta' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed WordPress and application hosting built on Google Cloud.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://kinsta.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Website files, database contents, visitor IP addresses, access logs, and account billing information.',
		],
	],

	'pantheon' => [
		'name'    => 'Pantheon',
		'aliases' => [ 'pantheon', 'getpantheon' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed WordPress and Drupal website operations platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://pantheon.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Website codebase, database contents, visitor request logs, and customer account details.',
		],
	],

	'pressable' => [
		'name'    => 'Pressable',
		'aliases' => [ 'pressable', 'automattic pressable' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed WordPress hosting operated by Automattic.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://pressable.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Website files, database contents, visitor IP addresses, access logs, and billing details.',
		],
	],

	'siteground' => [
		'name'    => 'SiteGround',
		'aliases' => [ 'siteground', 'site ground' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Shared, cloud, and managed WordPress hosting services.',
			'_ot_sub_website' => 'https://siteground.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Website files, database contents, visitor IP addresses, server logs, and customer account information.',
		],
	],

	'cloudways' => [
		'name'    => 'Cloudways',
		'aliases' => [ 'cloudways' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed cloud hosting platform for web applications and WordPress.',
			'_ot_sub_website' => 'https://cloudways.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Website files, database contents, visitor logs, and customer account and billing details.',
		],
	],

	'flywheel' => [
		'name'    => 'Flywheel',
		'aliases' => [ 'flywheel', 'getflywheel' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed WordPress hosting for designers and agencies, owned by WP Engine.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://getflywheel.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Website files, database contents, visitor IP addresses, access logs, and account billing information.',
		],
	],

	'woocommerce' => [
		'name'    => 'WooCommerce.com',
		'aliases' => [ 'woocommerce', 'woo' ],
		'fields'  => [
			'_ot_sub_purpose' => 'WooCommerce extension marketplace and account services operated by Automattic.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://woocommerce.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer account details, license keys, purchase history, and billing information.',
		],
	],

	// Observability, error tracking, uptime

	'bugsnag' => [
		'name'    => 'Bugsnag',
		'aliases' => [ 'bugsnag', 'smartbear bugsnag' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Error monitoring and application stability reporting.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://bugsnag.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application error stack traces, user identifiers, device and browser metadata, and request context.',
		],
	],

	'rollbar' => [
		'name'    => 'Rollbar',
		'aliases' => [ 'rollbar' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Error tracking and real-time exception monitoring.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://rollbar.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application error stack traces, user identifiers, environment metadata, and request payloads.',
		],
	],

	'appsignal' => [
		'name'    => 'AppSignal',
		'aliases' => [ 'appsignal' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Application performance monitoring and error tracking.',
			'_ot_sub_country' => 'NL',
			'_ot_sub_website' => 'https://appsignal.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Performance metrics, error stack traces, request metadata, and user identifiers.',
		],
	],

	'raygun' => [
		'name'    => 'Raygun',
		'aliases' => [ 'raygun' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Crash reporting, real user monitoring, and application performance monitoring.',
			'_ot_sub_country' => 'NZ',
			'_ot_sub_website' => 'https://raygun.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Error diagnostics, session traces, user identifiers, and browser and device metadata.',
		],
	],

	'pingdom' => [
		'name'    => 'Pingdom',
		'aliases' => [ 'pingdom', 'solarwinds pingdom' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Website uptime, page speed, and synthetic transaction monitoring.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://pingdom.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Monitored URLs, response timing metrics, and alert contact details.',
		],
	],

	'uptimerobot' => [
		'name'    => 'UptimeRobot',
		'aliases' => [ 'uptimerobot', 'uptime robot' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Website and service uptime monitoring with alerting.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://uptimerobot.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Monitored URLs, response status, latency metrics, and alert contact details.',
		],
	],

	'statuspage' => [
		'name'    => 'Statuspage',
		'aliases' => [ 'statuspage', 'atlassian statuspage' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Hosted status pages and incident communication for services.',
			'_ot_sub_country' => 'AU',
			'_ot_sub_website' => 'https://atlassian.com/software/statuspage',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Subscriber email addresses, incident notes, component statuses, and administrator account details.',
		],
	],

	'highlight-io' => [
		'name'    => 'Highlight.io',
		'aliases' => [ 'highlight', 'highlight.io' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Session replay, error monitoring, and application observability.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://highlight.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Session recordings, DOM events, console logs, error traces, and user identifiers.',
		],
	],

	'loggly' => [
		'name'    => 'Loggly',
		'aliases' => [ 'loggly', 'solarwinds loggly' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Cloud-based log aggregation and analysis.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://loggly.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application and server log events, request metadata, and any identifiers contained in logs.',
		],
	],

	'papertrail' => [
		'name'    => 'Papertrail',
		'aliases' => [ 'papertrail', 'solarwinds papertrail' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Cloud-hosted log management and live tail for servers and apps.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://papertrail.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Syslog and application log events, hostnames, and any identifiers included in log lines.',
		],
	],

	// Email marketing and CRM

	'activecampaign' => [
		'name'    => 'ActiveCampaign',
		'aliases' => [ 'activecampaign', 'active campaign' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Email marketing, marketing automation, and sales CRM.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://activecampaign.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Contact names, email addresses, engagement history, tags, and campaign interaction data.',
		],
	],

	'mailerlite' => [
		'name'    => 'MailerLite',
		'aliases' => [ 'mailerlite', 'mailer lite' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Email marketing, newsletters, and automation.',
			'_ot_sub_country' => 'LT',
			'_ot_sub_website' => 'https://mailerlite.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Subscriber email addresses, names, segmentation fields, and email engagement metrics.',
		],
	],

	'aweber' => [
		'name'    => 'AWeber',
		'aliases' => [ 'aweber' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Email marketing and autoresponder platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://aweber.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Subscriber email addresses, names, list membership, and campaign engagement data.',
		],
	],

	'getresponse' => [
		'name'    => 'GetResponse',
		'aliases' => [ 'getresponse', 'get response' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Email marketing, automation, and landing pages.',
			'_ot_sub_country' => 'PL',
			'_ot_sub_website' => 'https://getresponse.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Subscriber email addresses, names, segmentation data, and campaign engagement metrics.',
		],
	],

	'campaign-monitor' => [
		'name'    => 'Campaign Monitor',
		'aliases' => [ 'campaignmonitor', 'campaign monitor' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Email marketing and transactional email delivery.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://campaignmonitor.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Subscriber email addresses, names, list data, and email engagement metrics.',
		],
	],

	'drip' => [
		'name'    => 'Drip',
		'aliases' => [ 'drip', 'getdrip' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Email marketing and automation focused on ecommerce.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://drip.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer email addresses, purchase history, browsing events, and segmentation data.',
		],
	],

	'beehiiv' => [
		'name'    => 'Beehiiv',
		'aliases' => [ 'beehiiv' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Newsletter publishing, audience growth, and monetization platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://beehiiv.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Subscriber email addresses, names, engagement metrics, and referral data.',
		],
	],

	'emailoctopus' => [
		'name'    => 'EmailOctopus',
		'aliases' => [ 'emailoctopus', 'email octopus' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Email marketing and newsletter delivery service.',
			'_ot_sub_country' => 'GB',
			'_ot_sub_website' => 'https://emailoctopus.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Subscriber email addresses, names, list membership, and campaign engagement metrics.',
		],
	],

	'close' => [
		'name'    => 'Close',
		'aliases' => [ 'close', 'closecrm', 'close.io' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Sales CRM with built-in calling, email, and SMS.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://close.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Lead and contact details, call recordings, email threads, and sales pipeline data.',
		],
	],

	'attio' => [
		'name'    => 'Attio',
		'aliases' => [ 'attio' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Customer relationship management and customer data platform.',
			'_ot_sub_country' => 'GB',
			'_ot_sub_website' => 'https://attio.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Contact and company records, email metadata, and relationship activity history.',
		],
	],

	'copper' => [
		'name'    => 'Copper',
		'aliases' => [ 'copper', 'coppercrm' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Customer relationship management integrated with Google Workspace.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://copper.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Contact details, email threads, calendar events, and sales pipeline records.',
		],
	],

	// Payments

	'square' => [
		'name'    => 'Square',
		'aliases' => [ 'square', 'squareup' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Payment processing, point of sale, and merchant services.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://squareup.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Tokenized payment card details, customer names, billing information, and transaction records.',
		],
	],

	'adyen' => [
		'name'    => 'Adyen',
		'aliases' => [ 'adyen' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Global payment processing and unified commerce platform.',
			'_ot_sub_country' => 'NL',
			'_ot_sub_website' => 'https://adyen.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Tokenized payment card details, billing addresses, customer identifiers, and transaction metadata.',
		],
	],

	'mollie' => [
		'name'    => 'Mollie',
		'aliases' => [ 'mollie' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Payment processing for European businesses.',
			'_ot_sub_country' => 'NL',
			'_ot_sub_website' => 'https://mollie.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Tokenized payment details, customer names, billing information, and transaction records.',
		],
	],

	'gocardless' => [
		'name'    => 'GoCardless',
		'aliases' => [ 'gocardless', 'go cardless' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Bank debit and recurring payment collection.',
			'_ot_sub_country' => 'GB',
			'_ot_sub_website' => 'https://gocardless.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Bank account details, customer names, billing addresses, and mandate and transaction records.',
		],
	],

	'checkout-com' => [
		'name'    => 'Checkout.com',
		'aliases' => [ 'checkout', 'checkout.com' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Global online payment processing and acquiring.',
			'_ot_sub_country' => 'GB',
			'_ot_sub_website' => 'https://checkout.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Tokenized payment card details, billing addresses, customer identifiers, and transaction metadata.',
		],
	],

	'wise-business' => [
		'name'    => 'Wise Business',
		'aliases' => [ 'wise', 'transferwise', 'wise business' ],
		'fields'  => [
			'_ot_sub_purpose' => 'International business payments, multi-currency accounts, and FX.',
			'_ot_sub_country' => 'GB',
			'_ot_sub_website' => 'https://wise.com/business',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Business and beneficiary bank details, payer and payee names, and transaction records.',
		],
	],

	// Auth, identity, security

	'okta' => [
		'name'    => 'Okta',
		'aliases' => [ 'okta' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Workforce and customer identity, single sign-on, and access management.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://okta.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User directory attributes, authentication events, group memberships, and session metadata.',
		],
	],

	'onelogin' => [
		'name'    => 'OneLogin',
		'aliases' => [ 'onelogin', 'one login' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Identity and access management with single sign-on and MFA.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://onelogin.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User directory attributes, authentication events, and session and device metadata.',
		],
	],

	'duo' => [
		'name'    => 'Duo Security',
		'aliases' => [ 'duo', 'duo security', 'cisco duo' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Multi-factor authentication and device trust.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://duo.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User identifiers, device fingerprints, authentication events, and phone numbers for MFA.',
		],
	],

	'kinde' => [
		'name'    => 'Kinde',
		'aliases' => [ 'kinde' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Customer authentication, user management, and feature flags for applications.',
			'_ot_sub_country' => 'AU',
			'_ot_sub_website' => 'https://kinde.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'End user email addresses, names, authentication events, and profile attributes.',
		],
	],

	'supertokens' => [
		'name'    => 'SuperTokens',
		'aliases' => [ 'supertokens', 'super tokens' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Open-source authentication platform with managed and self-hosted options.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://supertokens.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'End user email addresses, hashed credentials, session tokens, and profile attributes.',
		],
	],

	'1password-business' => [
		'name'    => '1Password Business',
		'aliases' => [ '1password', 'onepassword', '1pw' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Password and secrets management for teams.',
			'_ot_sub_country' => 'CA',
			'_ot_sub_website' => 'https://1password.com/business',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Encrypted vault contents, user account details, and access and audit logs.',
		],
	],

	'bitwarden-business' => [
		'name'    => 'Bitwarden Business',
		'aliases' => [ 'bitwarden' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Open-source password and secrets management for teams.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://bitwarden.com/products/business',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Encrypted vault contents, user account details, and access and audit logs.',
		],
	],

	// Databases, backend, search

	'elastic-cloud' => [
		'name'    => 'Elastic Cloud',
		'aliases' => [ 'elastic', 'elasticsearch', 'elastic cloud' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed Elasticsearch, search, and observability service.',
			'_ot_sub_website' => 'https://elastic.co/cloud',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Indexed documents, search queries, and any identifiers contained in ingested data.',
		],
	],

	'redis-cloud' => [
		'name'    => 'Redis Cloud',
		'aliases' => [ 'redis', 'redis cloud', 'redis enterprise' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed Redis database and in-memory data platform.',
			'_ot_sub_website' => 'https://redis.io/cloud',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Cached and stored key-value data, including any identifiers written by the application.',
		],
	],

	'cockroachdb-cloud' => [
		'name'    => 'CockroachDB Cloud',
		'aliases' => [ 'cockroach', 'cockroachdb', 'cockroach cloud' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed distributed SQL database service.',
			'_ot_sub_website' => 'https://cockroachlabs.com/product/cockroachdb-cloud',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application database contents, including any personal data written by the application.',
		],
	],

	'fauna' => [
		'name'    => 'Fauna',
		'aliases' => [ 'fauna', 'faunadb' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Serverless distributed document and relational database.',
			'_ot_sub_website' => 'https://fauna.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application database contents, including any personal data written by the application.',
		],
	],

	'xata' => [
		'name'    => 'Xata',
		'aliases' => [ 'xata' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Serverless database platform with built-in search and analytics.',
			'_ot_sub_website' => 'https://xata.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Application database contents, including any personal data written by the application.',
		],
	],

	// AI and ML

	'cohere' => [
		'name'    => 'Cohere',
		'aliases' => [ 'cohere' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Large language model APIs for text generation, embeddings, and retrieval.',
			'_ot_sub_country' => 'CA',
			'_ot_sub_website' => 'https://cohere.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Prompt and completion text, embeddings input, and API usage metadata.',
		],
	],

	'hugging-face' => [
		'name'    => 'Hugging Face',
		'aliases' => [ 'huggingface', 'hugging face', 'hf' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Machine learning model hosting, inference APIs, and collaboration platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://huggingface.co',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Model inference inputs and outputs, uploaded datasets, and account metadata.',
		],
	],

	'groq' => [
		'name'    => 'Groq',
		'aliases' => [ 'groq' ],
		'fields'  => [
			'_ot_sub_purpose' => 'High-speed inference API for large language models.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://groq.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Prompt and completion text and API usage metadata.',
		],
	],

	'together-ai' => [
		'name'    => 'Together AI',
		'aliases' => [ 'together', 'together ai', 'togetherai' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Inference and fine-tuning APIs for open-source large language models.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://together.ai',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Prompt and completion text, fine-tuning datasets, and API usage metadata.',
		],
	],

	'fireworks-ai' => [
		'name'    => 'Fireworks AI',
		'aliases' => [ 'fireworks', 'fireworks ai' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Inference platform for open-source generative AI models.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://fireworks.ai',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Prompt and completion text, fine-tuning datasets, and API usage metadata.',
		],
	],

	'elevenlabs' => [
		'name'    => 'ElevenLabs',
		'aliases' => [ 'elevenlabs', 'eleven labs', '11labs' ],
		'fields'  => [
			'_ot_sub_purpose' => 'AI voice synthesis, text to speech, and voice cloning APIs.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://elevenlabs.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Submitted text, voice samples, generated audio, and API usage metadata.',
		],
	],

	'assemblyai' => [
		'name'    => 'AssemblyAI',
		'aliases' => [ 'assemblyai', 'assembly ai' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Speech to text, transcription, and audio intelligence APIs.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://assemblyai.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Uploaded audio recordings, generated transcripts, and API usage metadata.',
		],
	],

	'deepgram' => [
		'name'    => 'Deepgram',
		'aliases' => [ 'deepgram' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Speech to text and voice AI APIs for real-time and batch transcription.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://deepgram.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Uploaded audio, streamed voice data, generated transcripts, and API usage metadata.',
		],
	],

	'pinecone' => [
		'name'    => 'Pinecone',
		'aliases' => [ 'pinecone' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed vector database for similarity search and retrieval augmented generation.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://pinecone.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Stored vector embeddings, associated metadata, and query inputs.',
		],
	],

	'weaviate' => [
		'name'    => 'Weaviate',
		'aliases' => [ 'weaviate' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed vector database and AI-native search engine.',
			'_ot_sub_website' => 'https://weaviate.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Stored vector embeddings, associated metadata, and query inputs.',
		],
	],

	'qdrant' => [
		'name'    => 'Qdrant',
		'aliases' => [ 'qdrant' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed vector database for semantic search and AI applications.',
			'_ot_sub_website' => 'https://qdrant.tech',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Stored vector embeddings, associated metadata, and query inputs.',
		],
	],

	// Video and real-time media

	'mux' => [
		'name'    => 'Mux',
		'aliases' => [ 'mux' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Video streaming infrastructure, encoding, and playback analytics.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://mux.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Uploaded video assets, viewer IP addresses, playback events, and device metadata.',
		],
	],

	'vimeo' => [
		'name'    => 'Vimeo',
		'aliases' => [ 'vimeo' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Video hosting, streaming, and player services.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://vimeo.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Uploaded video assets, viewer IP addresses, playback events, and account information.',
		],
	],

	'wistia' => [
		'name'    => 'Wistia',
		'aliases' => [ 'wistia' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Business video hosting, player, and engagement analytics.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://wistia.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Uploaded video assets, viewer IP addresses, engagement events, and lead capture data.',
		],
	],

	'daily-co' => [
		'name'    => 'Daily.co',
		'aliases' => [ 'daily', 'daily.co' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Real-time video and audio APIs for embedded calls and meetings.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://daily.co',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Participant audio and video streams, session metadata, and optional recordings.',
		],
	],

	'livekit' => [
		'name'    => 'LiveKit',
		'aliases' => [ 'livekit', 'live kit' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Real-time audio, video, and data infrastructure built on WebRTC.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://livekit.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Participant audio and video streams, session metadata, and optional recordings.',
		],
	],

	'agora' => [
		'name'    => 'Agora',
		'aliases' => [ 'agora', 'agora.io' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Real-time voice, video, and interactive streaming APIs.',
			'_ot_sub_website' => 'https://agora.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Participant audio and video streams, session metadata, and optional recordings.',
		],
	],

	// Productivity and collaboration

	'jira' => [
		'name'    => 'Jira',
		'aliases' => [ 'jira', 'atlassian jira' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Issue tracking and agile project management.',
			'_ot_sub_country' => 'AU',
			'_ot_sub_website' => 'https://atlassian.com/software/jira',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User account details, issue contents, comments, attachments, and project metadata.',
		],
	],

	'confluence' => [
		'name'    => 'Confluence',
		'aliases' => [ 'confluence', 'atlassian confluence' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Team wiki and documentation collaboration.',
			'_ot_sub_country' => 'AU',
			'_ot_sub_website' => 'https://atlassian.com/software/confluence',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User account details, page contents, comments, attachments, and space metadata.',
		],
	],

	'clickup' => [
		'name'    => 'ClickUp',
		'aliases' => [ 'clickup', 'click up' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Project management, task tracking, and team collaboration.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://clickup.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User account details, task contents, comments, attachments, and workspace metadata.',
		],
	],

	'monday' => [
		'name'    => 'Monday.com',
		'aliases' => [ 'monday', 'monday.com' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Work management and team collaboration platform.',
			'_ot_sub_country' => 'IL',
			'_ot_sub_website' => 'https://monday.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User account details, board and item contents, comments, and attachments.',
		],
	],

	'airtable' => [
		'name'    => 'Airtable',
		'aliases' => [ 'airtable' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Cloud database and spreadsheet collaboration platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://airtable.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User account details, base records, attachments, and workspace metadata.',
		],
	],

	'coda' => [
		'name'    => 'Coda',
		'aliases' => [ 'coda', 'coda.io' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Collaborative documents combining text, tables, and automations.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://coda.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User account details, document contents, comments, and attachments.',
		],
	],

	'miro' => [
		'name'    => 'Miro',
		'aliases' => [ 'miro', 'realtimeboard' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Online collaborative whiteboard and visual workspace.',
			'_ot_sub_website' => 'https://miro.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User account details, board contents, comments, and attachments.',
		],
	],

	'loom' => [
		'name'    => 'Loom',
		'aliases' => [ 'loom', 'useloom' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Asynchronous video messaging and screen recording.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://loom.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Recorded video and audio, viewer identifiers, engagement events, and account details.',
		],
	],

	'microsoft-teams' => [
		'name'    => 'Microsoft Teams',
		'aliases' => [ 'teams', 'msteams', 'ms teams' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Team chat, meetings, and collaboration within Microsoft 365.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://microsoft.com/microsoft-teams',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User account details, chat messages, meeting recordings, files, and presence information.',
		],
	],

	// HR and payroll

	'gusto' => [
		'name'    => 'Gusto',
		'aliases' => [ 'gusto' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Payroll, benefits, and HR administration for US employers.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://gusto.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Employee names, Social Security numbers, bank details, compensation, and tax information.',
		],
	],

	'deel' => [
		'name'    => 'Deel',
		'aliases' => [ 'deel' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Global payroll, contractor management, and employer of record services.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://deel.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Worker names, tax identifiers, bank details, contracts, and compensation records.',
		],
	],

	'remote' => [
		'name'    => 'Remote',
		'aliases' => [ 'remote', 'remote.com' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Global employer of record, payroll, and contractor management.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://remote.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Worker names, tax identifiers, bank details, contracts, and compensation records.',
		],
	],

	'rippling' => [
		'name'    => 'Rippling',
		'aliases' => [ 'rippling' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Unified HR, payroll, IT, and finance platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://rippling.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Employee personal details, tax identifiers, bank information, device data, and app access logs.',
		],
	],

	'bamboohr' => [
		'name'    => 'BambooHR',
		'aliases' => [ 'bamboohr', 'bamboo hr' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Human resources information system for small and medium businesses.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://bamboohr.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Employee personal details, employment history, compensation, and performance records.',
		],
	],

	'personio' => [
		'name'    => 'Personio',
		'aliases' => [ 'personio' ],
		'fields'  => [
			'_ot_sub_purpose' => 'HR management, recruiting, and payroll for European businesses.',
			'_ot_sub_country' => 'DE',
			'_ot_sub_website' => 'https://personio.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Employee personal details, employment records, compensation, and applicant data.',
		],
	],

	// Docs and e-signature

	'docusign' => [
		'name'    => 'DocuSign',
		'aliases' => [ 'docusign', 'docu sign' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Electronic signature and agreement management.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://docusign.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Signer names, email addresses, IP addresses, signed documents, and audit trails.',
		],
	],

	'dropbox-sign' => [
		'name'    => 'Dropbox Sign',
		'aliases' => [ 'dropbox sign', 'hellosign' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Electronic signature service, formerly HelloSign.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://dropbox.com/sign',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Signer names, email addresses, IP addresses, signed documents, and audit trails.',
		],
	],

	'pandadoc' => [
		'name'    => 'PandaDoc',
		'aliases' => [ 'pandadoc', 'panda doc' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Document automation, proposals, and electronic signature.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://pandadoc.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Signer names, email addresses, IP addresses, document contents, and audit trails.',
		],
	],

	// Feature flags and experimentation

	'launchdarkly' => [
		'name'    => 'LaunchDarkly',
		'aliases' => [ 'launchdarkly', 'launch darkly' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Feature flag management and progressive delivery platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://launchdarkly.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'End user identifiers, targeting attributes, and feature evaluation events.',
		],
	],

	'statsig' => [
		'name'    => 'Statsig',
		'aliases' => [ 'statsig' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Feature flags, experimentation, and product analytics.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://statsig.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'End user identifiers, targeting attributes, and product event data.',
		],
	],

	'growthbook' => [
		'name'    => 'GrowthBook',
		'aliases' => [ 'growthbook', 'growth book' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Open-source feature flags and A/B testing platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://growthbook.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'End user identifiers, targeting attributes, and experiment event data.',
		],
	],

	'split' => [
		'name'    => 'Split',
		'aliases' => [ 'split', 'split.io' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Feature delivery and experimentation platform.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://split.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'End user identifiers, targeting attributes, and feature evaluation events.',
		],
	],

	'configcat' => [
		'name'    => 'ConfigCat',
		'aliases' => [ 'configcat', 'config cat' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Feature flag and configuration management service.',
			'_ot_sub_country' => 'HU',
			'_ot_sub_website' => 'https://configcat.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'End user identifiers, targeting attributes, and feature evaluation events.',
		],
	],

	// Workflow and background jobs

	'inngest' => [
		'name'    => 'Inngest',
		'aliases' => [ 'inngest' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Durable workflow and background job orchestration for developers.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://inngest.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Event payloads, function inputs and outputs, and execution logs.',
		],
	],

	'trigger-dev' => [
		'name'    => 'Trigger.dev',
		'aliases' => [ 'trigger', 'trigger.dev' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Background jobs and workflow orchestration platform for developers.',
			'_ot_sub_country' => 'GB',
			'_ot_sub_website' => 'https://trigger.dev',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Event payloads, job inputs and outputs, and execution logs.',
		],
	],

	'temporal-cloud' => [
		'name'    => 'Temporal Cloud',
		'aliases' => [ 'temporal', 'temporal cloud', 'temporal.io' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed durable execution and workflow orchestration service.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://temporal.io/cloud',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Workflow inputs and outputs, activity payloads, and execution history.',
		],
	],

	'hatchet' => [
		'name'    => 'Hatchet',
		'aliases' => [ 'hatchet' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Distributed task queue and background job orchestration.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://hatchet.run',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Task payloads, job inputs and outputs, and execution logs.',
		],
	],

	// iPaaS, integrations, internal tools

	'zapier' => [
		'name'    => 'Zapier',
		'aliases' => [ 'zapier' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Automation platform connecting SaaS apps with triggers and actions.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://zapier.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Data passed between connected applications, including any personal data in workflow payloads.',
		],
	],

	'make' => [
		'name'    => 'Make',
		'aliases' => [ 'make', 'integromat' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Visual automation and integration platform, formerly Integromat.',
			'_ot_sub_country' => 'CZ',
			'_ot_sub_website' => 'https://make.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Data passed between connected applications, including any personal data in scenario payloads.',
		],
	],

	'n8n-cloud' => [
		'name'    => 'n8n Cloud',
		'aliases' => [ 'n8n', 'n8n cloud' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed workflow automation platform based on n8n.',
			'_ot_sub_country' => 'DE',
			'_ot_sub_website' => 'https://n8n.io/cloud',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Data passed between connected applications, including any personal data in workflow payloads.',
		],
	],

	'pipedream' => [
		'name'    => 'Pipedream',
		'aliases' => [ 'pipedream' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Integration and workflow automation platform for developers.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://pipedream.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Event payloads, workflow inputs and outputs, and execution logs.',
		],
	],

	'retool' => [
		'name'    => 'Retool',
		'aliases' => [ 'retool' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Low-code platform for building internal tools and applications.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://retool.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'User account details, query results from connected data sources, and audit logs.',
		],
	],

	// Website builders and CMS

	'webflow' => [
		'name'    => 'Webflow',
		'aliases' => [ 'webflow' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Visual website builder, CMS, and hosting.',
			'_ot_sub_country' => 'US',
			'_ot_sub_website' => 'https://webflow.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Website content, form submissions, visitor analytics, and customer account details.',
		],
	],

	'framer' => [
		'name'    => 'Framer',
		'aliases' => [ 'framer' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Website builder and design tool with hosting.',
			'_ot_sub_country' => 'NL',
			'_ot_sub_website' => 'https://framer.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Website content, form submissions, visitor analytics, and customer account details.',
		],
	],

	'shopify' => [
		'name'    => 'Shopify',
		'aliases' => [ 'shopify' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Ecommerce platform for online stores and retail point of sale.',
			'_ot_sub_country' => 'CA',
			'_ot_sub_website' => 'https://shopify.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Customer names, email addresses, shipping addresses, order history, and payment metadata.',
		],
	],

	'contentful' => [
		'name'    => 'Contentful',
		'aliases' => [ 'contentful' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Headless content management system and content delivery APIs.',
			'_ot_sub_country' => 'DE',
			'_ot_sub_website' => 'https://contentful.com',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Content entries, media assets, and editor account details.',
		],
	],

	'sanity' => [
		'name'    => 'Sanity',
		'aliases' => [ 'sanity', 'sanity.io' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Headless content platform with structured content and real-time APIs.',
			'_ot_sub_country' => 'NO',
			'_ot_sub_website' => 'https://sanity.io',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Content documents, media assets, and editor account details.',
		],
	],

	'strapi-cloud' => [
		'name'    => 'Strapi Cloud',
		'aliases' => [ 'strapi', 'strapi cloud' ],
		'fields'  => [
			'_ot_sub_purpose' => 'Managed hosting for the Strapi headless CMS.',
			'_ot_sub_country' => 'FR',
			'_ot_sub_website' => 'https://strapi.io/cloud',
		],
		'fields_review' => [
			'_ot_sub_data_processed' => 'Content entries, media assets, and editor account details.',
		],
	],

];
