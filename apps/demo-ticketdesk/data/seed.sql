-- Demo seed data for TicketDesk.
INSERT INTO customers (name, email) VALUES
('Ada Lovelace', 'ada@example.com'),
('Alan Turing', 'alan@example.com'),
('Grace Hopper', 'grace@example.com'),
('Katherine Johnson', 'katherine@example.com');

INSERT INTO agents (name, email) VALUES
('Sam Rivera', 'sam@support.example.com'),
('Riley Chen', 'riley@support.example.com');

INSERT INTO tickets (customer_id, agent_id, subject, body, status, priority) VALUES
(1, 1, 'Login fails after password reset', 'User cannot sign in following a reset email.', 'open', 'high'),
(1, 2, 'Logout is slow on mobile', 'Sign-out takes ~10s on the iOS app.', 'pending', 'low'),
(2, 1, 'Payment declined at checkout', 'Card is valid but the gateway rejects it.', 'open', 'urgent'),
(2, NULL, 'Export tickets to CSV', 'Feature request for a CSV export button.', 'open', 'normal'),
(3, 2, 'Login loop on Safari', 'Redirect loop when logging in via Safari.', 'pending', 'high'),
(3, 1, 'Dashboard numbers look wrong', 'Open count does not match the list.', 'closed', 'normal'),
(4, 2, 'Webhook retries flooding logs', 'Failed webhook retried too aggressively.', 'open', 'high'),
(4, 1, 'Add dark mode', 'Please add a dark theme to the portal.', 'closed', 'low'),
(1, NULL, 'Login email not received', 'Magic-link email never arrives.', 'open', 'normal'),
(2, 2, 'Slow report generation', 'Monthly report takes minutes to build.', 'pending', 'normal'),
(3, 1, 'Password rules too strict', 'Cannot set a memorable password.', 'closed', 'low'),
(4, NULL, 'Timezone wrong on receipts', 'Receipts show UTC instead of local time.', 'open', 'normal');
