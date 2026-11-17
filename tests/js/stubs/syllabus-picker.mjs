// The filepicker of Moodle, as a test drives it: what the person picks is whatever the test says.
export const picker = {next: null, asked: 0};
export const pickFile = async() => {
    picker.asked++;
    return picker.next;
};
