package server

import (
	"github.com/prometheus/client_golang/prometheus"

	"chatapp/gateway/internal/registry"
)

type metrics struct {
	registry *prometheus.Registry

	connects            *prometheus.CounterVec
	disconnects         *prometheus.CounterVec
	authFailures        *prometheus.CounterVec
	upgradeRejections   *prometheus.CounterVec
	slowConsumers       prometheus.Counter
	inboundThrottled    prometheus.Counter
	tokenRenewals       prometheus.Counter
	eventsDelivered     *prometheus.CounterVec
	eventsDropped       *prometheus.CounterVec
	subscriptionErrors  *prometheus.CounterVec
	duplicateEvents     prometheus.Counter
	presenceTransitions *prometheus.CounterVec
	presenceErrors      prometheus.Counter
	typingErrors        prometheus.Counter
	typingEvents        *prometheus.CounterVec
	resyncs             *prometheus.CounterVec
}

func newMetrics(connections *registry.Registry) *metrics {
	registry := prometheus.NewRegistry()

	m := &metrics{
		registry: registry,
		connects: prometheus.NewCounterVec(prometheus.CounterOpts{
			Name: "gateway_websocket_connects_total",
			Help: "Total number of authenticated WebSocket connections.",
		}, []string{"instance"}),
		disconnects: prometheus.NewCounterVec(prometheus.CounterOpts{
			Name: "gateway_websocket_disconnects_total",
			Help: "Total number of closed WebSocket connections.",
		}, []string{"instance", "reason"}),
		authFailures: prometheus.NewCounterVec(prometheus.CounterOpts{
			Name: "gateway_websocket_auth_failures_total",
			Help: "Total number of rejected WebSocket authentication attempts.",
		}, []string{"reason"}),
		upgradeRejections: prometheus.NewCounterVec(prometheus.CounterOpts{
			Name: "gateway_websocket_upgrade_rejections_total",
			Help: "Total number of rejected WebSocket upgrades.",
		}, []string{"reason"}),
		slowConsumers: prometheus.NewCounter(prometheus.CounterOpts{
			Name: "gateway_slow_consumers_total",
			Help: "Total number of connections closed because their outbound buffer was full.",
		}),
		inboundThrottled: prometheus.NewCounter(prometheus.CounterOpts{
			Name: "gateway_inbound_throttled_total",
			Help: "Total number of inbound client messages dropped by per-connection rate limiting.",
		}),
		tokenRenewals: prometheus.NewCounter(prometheus.CounterOpts{
			Name: "gateway_token_renewals_total",
			Help: "Total number of connections that refreshed their gateway token in place.",
		}),
		eventsDelivered: prometheus.NewCounterVec(prometheus.CounterOpts{
			Name: "gateway_events_delivered_total",
			Help: "Total number of events queued for connected clients.",
		}, []string{"source"}),
		eventsDropped: prometheus.NewCounterVec(prometheus.CounterOpts{
			Name: "gateway_events_dropped_total",
			Help: "Total number of events dropped before delivery.",
		}, []string{"reason"}),
		subscriptionErrors: prometheus.NewCounterVec(prometheus.CounterOpts{
			Name: "gateway_subscription_errors_total",
			Help: "Total number of conversation subscription errors.",
		}, []string{"reason"}),
		duplicateEvents: prometheus.NewCounter(prometheus.CounterOpts{
			Name: "gateway_duplicate_events_total",
			Help: "Total number of durable events skipped because they were already delivered.",
		}),
		presenceTransitions: prometheus.NewCounterVec(prometheus.CounterOpts{
			Name: "gateway_presence_transitions_total",
			Help: "Total number of user presence transitions broadcast to conversations.",
		}, []string{"state"}),
		presenceErrors: prometheus.NewCounter(prometheus.CounterOpts{
			Name: "gateway_presence_errors_total",
			Help: "Total number of presence tracking failures.",
		}),
		typingErrors: prometheus.NewCounter(prometheus.CounterOpts{
			Name: "gateway_typing_errors_total",
			Help: "Total number of typing state failures.",
		}),
		typingEvents: prometheus.NewCounterVec(prometheus.CounterOpts{
			Name: "gateway_typing_events_total",
			Help: "Total number of typing events accepted from clients.",
		}, []string{"action"}),
		resyncs: prometheus.NewCounterVec(prometheus.CounterOpts{
			Name: "gateway_client_resyncs_total",
			Help: "Total number of clients told to resync a conversation.",
		}, []string{"reason"}),
	}

	registry.MustRegister(
		prometheus.NewGaugeFunc(prometheus.GaugeOpts{
			Name: "gateway_connections",
			Help: "Current number of authenticated WebSocket connections.",
		}, func() float64 { return float64(connections.Count()) }),
		prometheus.NewGaugeFunc(prometheus.GaugeOpts{
			Name: "gateway_connected_users",
			Help: "Current number of users with at least one WebSocket connection.",
		}, func() float64 { return float64(connections.UserCount()) }),
		prometheus.NewGaugeFunc(prometheus.GaugeOpts{
			Name: "gateway_subscriptions",
			Help: "Current number of authorized conversation subscriptions.",
		}, func() float64 { return float64(connections.SubscriptionTotal()) }),
		m.connects,
		m.disconnects,
		m.authFailures,
		m.upgradeRejections,
		m.slowConsumers,
		m.inboundThrottled,
		m.tokenRenewals,
		m.eventsDelivered,
		m.eventsDropped,
		m.subscriptionErrors,
		m.duplicateEvents,
		m.presenceTransitions,
		m.presenceErrors,
		m.typingErrors,
		m.typingEvents,
		m.resyncs,
	)

	return m
}
