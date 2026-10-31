package outbox

import "github.com/nats-io/nats.go"

// NATSPublisher publishes outbox payloads to the NATS subject the gateway
// subscribes to.
type NATSPublisher struct {
	conn *nats.Conn
}

func NewNATSPublisher(conn *nats.Conn) *NATSPublisher {
	return &NATSPublisher{conn: conn}
}

func (p *NATSPublisher) Publish(subject string, payload []byte) error {
	return p.conn.Publish(subject, payload)
}

// Flush blocks until the server acknowledges every publish buffered so far,
// which is what makes "published" in the outbox mean "the broker has it".
func (p *NATSPublisher) Flush() error {
	return p.conn.Flush()
}
