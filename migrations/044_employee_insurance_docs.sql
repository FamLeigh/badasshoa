-- 044: attach documents directly to employee records and insurance policies
ALTER TABLE documents
    ADD COLUMN employee_id  INT NULL AFTER user_id,
    ADD COLUMN insurance_id INT NULL AFTER employee_id,
    ADD KEY idx_doc_employee  (employee_id),
    ADD KEY idx_doc_insurance (insurance_id),
    ADD CONSTRAINT fk_docs_employee  FOREIGN KEY (employee_id)  REFERENCES employees(id)          ON DELETE CASCADE,
    ADD CONSTRAINT fk_docs_insurance FOREIGN KEY (insurance_id) REFERENCES insurance_policies(id) ON DELETE CASCADE;
