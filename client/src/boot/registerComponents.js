import Injector from 'lib/Injector';
import ContentCreatorModal from '../components/ContentCreator/ContentCreatorModal';

export default () => {
  Injector.component.registerMany({
    ContentCreatorModal
  });
};
