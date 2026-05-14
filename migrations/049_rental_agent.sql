-- Add rental_agent kind to association_contacts and link renters to their agent on users.

ALTER TABLE association_contacts
    MODIFY COLUMN kind
        ENUM('emergency','non_emergency','contractor','utility','rental_agent','other')
        NOT NULL DEFAULT 'other';

ALTER TABLE users
    ADD COLUMN rental_agent_contact_id INT NULL AFTER status,
    ADD CONSTRAINT fk_users_rental_agent
        FOREIGN KEY (rental_agent_contact_id)
        REFERENCES association_contacts(id)
        ON DELETE SET NULL;
