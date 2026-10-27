"""Check cross-source preservation and identity without calling marketplace APIs."""
import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
spec=importlib.util.spec_from_file_location('flowwow_sync',Path(__file__).resolve().parents[1]/'sync-flowwow.py')
sync=importlib.util.module_from_spec(spec)
spec.loader.exec_module(sync)

class RefreshIdentity(unittest.TestCase):
    def test_renaming_preserves_identity_override_and_other_source(self):
        previous={'id':'curated-id','flowwowProductId':42,'source':'flowwow',
                  'url':'https://flowwow.com/old-name/','title':'Старое имя','status':'preorder',
                  'category':'plant','images':['assets/catalog/old.jpg'],'available':False}
        secondary={'id':'avito-17','source':'avito','url':'https://www.avito.ru/17',
                   'title':'Грунт для флорариума','price':250,'available':True,'status':'ready','images':['https://example.com/soil.jpg']}
        api_item={'productId':42,'url':'https://flowwow.com/new-name/','name':'Новое имя',
                  'price':1000,'stock':1,'isActive':True,'isArchived':False,'images':['https://example.com/current.jpg']}
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder);(root/'data').mkdir()
            (root/'data/catalog.json').write_text(json.dumps({'products':[previous,secondary],'featured':['curated-id']}))
            (root/'data/catalog-groups.json').write_text(json.dumps({'curated-id':'potted'}))
            with patch.object(sync,'ROOT',root), patch.object(sync,'TOKEN','dummy'), patch.object(sync,'pages',return_value=[api_item]), patch.object(sync,'category_names',return_value={}):
                sync.main()
            result=json.loads((root/'data/catalog.json').read_text())
            self.assertEqual(len(result['products']),2)
            current=result['products'][0]
            self.assertEqual(current['id'],'curated-id')
            self.assertEqual(current['catalogGroup'],'potted')
            self.assertEqual(current['title'],'Новое имя')
            self.assertEqual(result['products'][1]['id'],'avito-17')
            self.assertEqual(result['products'][1]['catalogGroup'],'material')
            self.assertEqual(result['featured'],['curated-id'])

if __name__=='__main__': unittest.main()
