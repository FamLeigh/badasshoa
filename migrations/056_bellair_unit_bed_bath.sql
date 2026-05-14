-- Bellair Condominiums (association_id = 1) — bedroom and bathroom counts.
-- Source: bellair_units bedrooms bathroom count.csv supplied 2026-05-14.

UPDATE units SET bedrooms = 0, baths = 1.0 WHERE association_id = 1 AND unit_number IN ('117','118','217','218','317','318','417','418','517','518','617','618');
UPDATE units SET bedrooms = 1, baths = 1.0 WHERE association_id = 1 AND unit_number IN ('102','103','104','105','106','107','115','120','202','203','204','205','206','207','215','220','302','303','304','305','306','307','315','320','402','403','404','405','406','407','415','420','502','503','504','505','506','507','515','520','602','603','604','605','606','607','615','620');
UPDATE units SET bedrooms = 2, baths = 2.0 WHERE association_id = 1 AND unit_number IN ('101','108','109','110','112','114','116','119','201','208','209','210','212','214','216','219','301','308','309','310','312','314','316','319','401','408','409','410','412','414','416','419','501','508','509','510','512','514','516','519','601','608','609','610','612','614','616','619');
UPDATE units SET bedrooms = 3, baths = 2.0 WHERE association_id = 1 AND unit_number IN ('111','121','211','221','311','321','411','421','511','521','611','621');
